<?php

namespace Storyfeed\Support;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Throwable;

/**
 * The identity map behind FeedContext::model() — one per payload build.
 *
 * A resolver receives a snapshot and nothing else, so anything the snapshot
 * does not carry is unavailable, permanently. Widening the snapshot is the
 * wrong direction: it is per-row storage, and a fact copied into it goes
 * stale and has to be trickled. The model itself was never offered because
 * of cost, not principle — a resolver that loads its row is an N+1 across
 * the page. This class is what makes the cost avoidable (issue #4).
 *
 * BATCHED BY CLASS, NOT ONE ROW AT A TIME. By the time any resolver runs,
 * the presenter is already holding a snapshot for every entity on the page,
 * so it knows the complete (morph type, id) set before the first call. The
 * presenter seeds that set here; the first entity of a class to ask for its
 * model loads EVERY seeded id of that class in one whereKey(), and every
 * later entity of the class is a map hit:
 *
 *     first Customer asks   →  Customer::whereKey([4, 9, 12, 40])->get()   one query
 *     every later Customer  →  identity map hit, no query
 *
 * Ten distinct classes on a page is ten queries whether the page holds
 * twenty nodes or two hundred. An id that was never seeded — a context built
 * by hand, the AS2 serializer's one-activity-at-a-time path — is still
 * served, as a single lookup: correct, only not amortised.
 *
 * SCOPES APPLY. The batch goes through `$class::query()`, so a global scope
 * — SoftDeletes above all — hides what it would hide anywhere else. A
 * soft-deleted row therefore answers null by default: the entity is
 * unlinked rather than linked to a page that 500s. `withTrashed` is an
 * explicit opt-in, and only offered to classes that actually soft-delete.
 *
 * A MISSING ROW IS NULL, AND SO IS A FAILURE. Snapshot present, row gone:
 * the resolver gets null, decides what to do without the model, and the
 * activity renders. A batch that throws — a dropped table, a broken scope —
 * is reported once, every id it covered is recorded as absent so nothing
 * retries it per entity, and the read path never sees the exception.
 *
 * RELATIONS RIDE THE BATCH. `with:` on the FIRST call of a class is eager
 * loaded in the same query. Asked later, the relations are loaded across
 * the whole already-hydrated collection in one query per relation, never
 * per model — so consistency is a nicety, not a requirement.
 *
 * PER BUILD, NEVER SHARED. A map that outlived a page would serve one
 * page's models to the next — a queued digest rendering a customer after
 * a rename would show the name from before it. The presenter takes a fresh
 * one for every page (NodePresenter::forPage()), for the same reason it
 * takes its feed name by copy rather than setter.
 *
 * THE OFF SWITCH. `storyfeed.hydration.enabled = false` makes every call
 * answer null with no query and no exception, for an application that
 * needs a no-queries guarantee on a hot surface. Links degrade; nothing
 * throws; the resolver's null branch is the one it already had to write.
 */
final class ModelHydrator
{
    /** @var array<string, array<string, int|string>> alias => [id => id], everything the page holds */
    private array $seeded = [];

    /** @var array<string, array<string, Model|null>> batch key => [id => model, or null when absent] */
    private array $loaded = [];

    /** @var array<string, EloquentCollection<int, Model>> batch key => the hydrated models, for loadMissing() */
    private array $collections = [];

    /** @var array<string, array<string, true>> batch key => relation names already loaded */
    private array $relations = [];

    /** @var array<string, true> morph aliases whose resolver asked for a model — the seam for the doctor (issue #5) */
    private array $requested = [];

    private bool $enabled;

    public function __construct(?bool $enabled = null)
    {
        $this->enabled = $enabled ?? (bool) config('storyfeed.hydration.enabled', true);
    }

    /**
     * Tell the map what the page holds, so the first request for a class
     * can load the whole class at once.
     */
    public function seed(?string $type, int|string|null $id): void
    {
        if ($type === null || $id === null) {
            return;
        }

        $this->seeded[$type][(string) $id] = $id;
    }

    /**
     * The live model for one entity — from the map when its class has been
     * loaded, from one batched query when it has not, null when the row is
     * gone, hidden by a scope, unresolvable, or hydration is switched off.
     *
     * @param  array<int|string, mixed>  $with  relations to eager load, in the shape Builder::with() accepts
     */
    public function model(string $type, int|string|null $id, array $with = [], bool $withTrashed = false): ?Model
    {
        $this->requested[$type] = true;

        if (! $this->enabled || $id === null) {
            return null;
        }

        $class = MorphResolver::classFor($type);

        if ($class === null || ! is_a($class, Model::class, true)) {
            return null;
        }

        $withTrashed = $withTrashed && in_array(SoftDeletes::class, class_uses_recursive($class), true);
        $key = $class.($withTrashed ? '#trashed' : '');
        $id = (string) $id;

        if (! array_key_exists($id, $this->loaded[$key] ?? [])) {
            $this->load($key, $class, $type, $id, $with, $withTrashed);
        } elseif ($with !== []) {
            $this->loadRelations($key, $with);
        }

        return $this->loaded[$key][$id] ?? null;
    }

    /**
     * The morph aliases whose resolver asked for a model during this build,
     * whether or not one came back. Not consulted by the read path; kept so
     * a diagnostic can name the Feedables that hydrate.
     *
     * @return list<string>
     */
    public function requested(): array
    {
        return array_keys($this->requested);
    }

    /**
     * One query for every seeded id of the class plus the one being asked
     * for, which is the same set on a seeded page and a single lookup off it.
     *
     * @param  class-string<Model>  $class
     * @param  array<int|string, mixed>  $with
     */
    private function load(string $key, string $class, string $type, string $id, array $with, bool $withTrashed): void
    {
        $ids = [$id => $id];

        foreach ($this->seeded as $alias => $seeded) {
            if ($alias === $type || MorphResolver::classFor($alias) === $class) {
                $ids += $seeded;
            }
        }

        // Ids already answered under this key stay answered; loading them
        // again would replace an instance a resolver may still be holding.
        $ids = array_diff_key($ids, $this->loaded[$key] ?? []);

        $this->loaded[$key] ??= [];
        $this->collections[$key] ??= new EloquentCollection;

        try {
            $query = $class::query()->whereKey(array_values($ids));

            // What withTrashed() does, named by the scope it lifts: the
            // macro only exists on builders of soft-deleting models, which
            // the guard above has already established.
            if ($withTrashed) {
                $query->withoutGlobalScope(SoftDeletingScope::class);
            }

            if ($with !== []) {
                $query->with($with);
            }

            $models = $query->get();
        } catch (Throwable $e) {
            report($e);

            foreach ($ids as $missing) {
                $this->loaded[$key][(string) $missing] = null;
            }

            return;
        }

        foreach ($ids as $missing) {
            $this->loaded[$key][(string) $missing] = null;
        }

        foreach ($models as $model) {
            $this->loaded[$key][(string) $model->getKey()] = $model;
            $this->collections[$key]->push($model);
        }

        foreach ($this->relationNames($with) as $relation) {
            $this->relations[$key][$relation] = true;
        }
    }

    /**
     * Relations asked for after the class was loaded: one eager query per
     * relation across the whole collection, never one per model.
     *
     * @param  array<int|string, mixed>  $with
     */
    private function loadRelations(string $key, array $with): void
    {
        $missing = [];

        foreach ($with as $name => $constraint) {
            $relation = is_string($name) ? $name : $constraint;

            if (! isset($this->relations[$key][$relation])) {
                $missing[$name] = $constraint;
                $this->relations[$key][$relation] = true;
            }
        }

        if ($missing === [] || $this->collections[$key]->isEmpty()) {
            return;
        }

        try {
            $this->collections[$key]->loadMissing($missing);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param  array<int|string, mixed>  $with
     * @return list<string>
     */
    private function relationNames(array $with): array
    {
        $names = [];

        foreach ($with as $name => $constraint) {
            $names[] = is_string($name) ? $name : (string) $constraint;
        }

        return $names;
    }
}
