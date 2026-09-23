<?php

namespace Storyfeed\Support;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use ReflectionClass;
use Storyfeed\Actions\RestoreToFeed;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Actions\TombstoneEntity;
use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedableRegistration;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedMedia;
use Storyfeed\FeedNoun;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\StoryfeedManager;
use Stringable;

/**
 * Which models are Feedable, and how to ask one for its two halves.
 *
 * A model is Feedable when it implements the contract, or when a service
 * provider registered it with `Storyfeed::feedable()` because it's a class
 * the app doesn't own. Every place in core that used to test
 * `instanceof Feedable` asks isFeedable() here, and every place that called
 * `toFeed()` or `::feedMedia()` goes through toFeed() and feedMedia(), so a
 * registered class is treated the same everywhere.
 *
 * It also keeps the `feedMediaUsing()` closures models register in
 * `booted()`, and the app-wide label guesser. A container singleton rather
 * than state on the manager, so `Storyfeed::fake()` swapping the manager
 * keeps every registration.
 */
class Feedables
{
    /** @var array<class-string<Model>, FeedableRegistration<Model>> */
    private array $registrations = [];

    /** @var array<class-string<Model>, Closure(FeedContext, FeedMedia): (FeedMedia|string|null)> */
    private array $mediaResolvers = [];

    /** @var (Closure(Model): ?string)|null */
    private ?Closure $labelGuesser = null;

    /**
     * Feedable classes heard through a parent that is not Feedable, keyed
     * by that parent. See listenThroughParents().
     *
     * @var array<class-string<Model>, list<class-string<Model>>>
     */
    private array $subclasses = [];

    /**
     * Per parent, the subclasses that share its table, by morph alias.
     * Worked out on the parent's first event, not at boot.
     *
     * @var array<class-string<Model>, array<string, class-string<Model>>>
     */
    private array $subclassAliases = [];

    private bool $participantsInstalled = false;

    /**
     * Treat a class you don't own as Feedable. Calling it again for the same
     * class returns the same registration.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $class
     * @return FeedableRegistration<TModel>
     */
    public function register(string $class): FeedableRegistration
    {
        if (isset($this->registrations[$class])) {
            /** @var FeedableRegistration<TModel> */
            return $this->registrations[$class];
        }

        if (is_a($class, Feedable::class, true)) {
            throw new InvalidArgumentException(
                "[{$class}] already implements Feedable, so it can't also be registered with Storyfeed::feedable(). "
                .'Describe it in the class (describeFeed(), toFeed() or feedMedia()), or remove the interface.'
            );
        }

        $this->listen($class);

        $registration = $this->registrations[$class] = new FeedableRegistration($class);

        $this->listenThroughParents([$class]);

        return $registration;
    }

    /**
     * The lifecycle the InteractsWithFeed trait wires for its own models,
     * wired on a class that can't use the trait. Listened to through the
     * dispatcher by event name, which works from a provider's register() as
     * well as boot(); `$class::saved()` would be dropped silently before the
     * database provider has set the model dispatcher.
     *
     * @param  class-string<Model>  $class
     */
    private function listen(string $class): void
    {
        $events = app(Dispatcher::class);

        $events->listen("eloquent.saved: {$class}", function (Model $model): void {
            if (app(StoryfeedManager::class)->isRecording()) {
                (new SnapshotEntity)($model);
            }
        });

        $events->listen("eloquent.deleted: {$class}", function (Model $model): void {
            if (app(StoryfeedManager::class)->isRecording()) {
                (new TombstoneEntity)($model);
            }
        });

        // Both fire only for a class using SoftDeletes; harmless otherwise.
        $events->listen("eloquent.forceDeleted: {$class}", function (Model $model): void {
            if (app(StoryfeedManager::class)->isRecording()) {
                (new TombstoneEntity)->forceDeleted($model);
            }
        });

        $events->listen("eloquent.restored: {$class}", function (Model $model): void {
            if (app(StoryfeedManager::class)->isRecording()) {
                (new RestoreToFeed)($model);
            }
        });
    }

    /**
     * Hear a Feedable subclass's rows being deleted through its parent.
     *
     * A class like `FeedablePhoto extends Media implements Feedable` exists
     * so `object_type` resolves to something Feedable, but the app deletes
     * the row as a `Media`: Eloquent fires `eloquent.deleted: …\Media`, and
     * the subclass's own listeners never run. Without this the tombstone
     * waits for the trickle, which finds the row gone on its next run.
     *
     * So for each such class, the parent's delete, force-delete and restore
     * events are heard too, walking up to (not including) the first
     * ancestor that is itself Feedable: that one hears its own events.
     * Abstract ancestors are skipped, because no instance fires as one, and
     * the walk stops at the framework's own classes.
     * Nothing is listened to when no class needs it, so an app without such
     * a subclass pays nothing on any delete.
     *
     * A parent row need not belong to the subclass — `media` holds every
     * attachment in the app — so a deletion is matched by the subclass's
     * alias and the row's key against feed_participants, and does nothing
     * when no activity names that pair: one indexed query per deletion.
     * The trickle still sweeps, and is the safety net for deletions no
     * event reports.
     *
     * Called at boot with every class in the morph map, and by register().
     * Idempotent.
     *
     * @param  iterable<class-string>  $classes
     */
    public function listenThroughParents(iterable $classes): void
    {
        foreach ($classes as $class) {
            if (! is_a($class, Model::class, true) || ! $this->isFeedable($class)) {
                continue;
            }

            foreach ($this->nonFeedableParents($class) as $parent) {
                if (in_array($class, $this->subclasses[$parent] ?? [], true)) {
                    continue;
                }

                if (! isset($this->subclasses[$parent])) {
                    $this->listenToParent($parent);
                }

                $this->subclasses[$parent][] = $class;
                unset($this->subclassAliases[$parent]);
            }
        }
    }

    /**
     * The concrete ancestors of a Feedable model that are not Feedable, up
     * to the first one that is.
     *
     * @param  class-string<Model>  $class
     * @return list<class-string<Model>>
     */
    public function nonFeedableParents(string $class): array
    {
        $parents = [];

        for ($parent = get_parent_class($class); $parent !== false && $parent !== Model::class; $parent = get_parent_class($parent)) {
            // The framework's own bases (Foundation\Auth\User, Pivot) are
            // concrete, but no app deletes a row as one: every User model
            // would otherwise be heard through Auth\User.
            if (! is_a($parent, Model::class, true) || $this->isFeedable($parent) || str_starts_with($parent, 'Illuminate\\')) {
                break;
            }

            if (! (new ReflectionClass($parent))->isAbstract()) {
                $parents[] = $parent;
            }
        }

        return $parents;
    }

    /**
     * Whether a Feedable class's deletions through its parents are heard.
     *
     * @param  class-string  $class
     */
    public function listensThroughParents(string $class): bool
    {
        foreach ($this->subclasses as $subclasses) {
            if (in_array($class, $subclasses, true)) {
                return true;
            }
        }

        return false;
    }

    /** @param  class-string<Model>  $parent */
    private function listenToParent(string $parent): void
    {
        $events = app(Dispatcher::class);

        $events->listen("eloquent.deleted: {$parent}", function (Model $row) use ($parent): void {
            // A force delete fires `deleted` first; `forceDeleted` follows
            // and leaves the permanent tombstone.
            if (! $this->hearsParents() || (method_exists($row, 'isForceDeleting') && $row->isForceDeleting())) {
                return;
            }

            foreach ($this->referenced($parent, $row) as $subclass) {
                (new TombstoneEntity)($this->as($subclass, $row));
            }
        });

        $events->listen("eloquent.forceDeleted: {$parent}", function (Model $row) use ($parent): void {
            if (! $this->hearsParents()) {
                return;
            }

            foreach ($this->referenced($parent, $row, forcing: true) as $subclass) {
                (new TombstoneEntity)->forceDeleted($this->as($subclass, $row));
            }
        });

        $events->listen("eloquent.restored: {$parent}", function (Model $row) use ($parent): void {
            if (! $this->hearsParents() || ($aliases = $this->aliasesFor($parent)) === []) {
                return;
            }

            $model = config('storyfeed.models.tombstone', FeedTombstone::class);

            /** @var FeedTombstone $tombstone */
            foreach ($model::query()->whereIn('model_type', array_keys($aliases))->where('model_id', (string) $row->getKey())->get() as $tombstone) {
                (new RestoreToFeed)->tombstone($tombstone, $this->as($aliases[$tombstone->model_type], $row));
            }
        });
    }

    private function hearsParents(): bool
    {
        if (! app(StoryfeedManager::class)->isRecording() || ! TombstoneEntity::installed()) {
            return false;
        }

        return $this->participantsInstalled
            || ($this->participantsInstalled = Schema::hasTable(SyncParticipants::table()));
    }

    /**
     * The subclasses whose alias and this row's key fill a role on some
     * activity. For a force delete of a row that was trashed first, the
     * soft delete already moved those activities onto a tombstone, so the
     * tombstones are asked instead — one query either way, and the
     * participants only when no tombstone answers (a soft delete made while
     * recording was off).
     *
     * @param  class-string<Model>  $parent
     * @return list<class-string<Model>>
     */
    private function referenced(string $parent, Model $row, bool $forcing = false): array
    {
        if (($aliases = $this->aliasesFor($parent)) === []) {
            return [];
        }

        $key = (string) $row->getKey();
        $found = [];

        if ($forcing && TombstoneEntity::trashedAt($row) !== null) {
            $model = config('storyfeed.models.tombstone', FeedTombstone::class);
            $found = $model::query()->whereIn('model_type', array_keys($aliases))->where('model_id', $key)->pluck('model_type')->all();
        }

        if ($found === []) {
            $found = DB::table(SyncParticipants::table())
                ->whereIn('entity_type', array_keys($aliases))
                ->where('entity_id', $key)
                ->distinct()
                ->pluck('entity_type')
                ->all();
        }

        return array_values(array_map(fn ($alias) => $aliases[$alias], array_unique($found)));
    }

    /**
     * The subclasses heard through a parent, by alias — only those that
     * read the parent's own table on the same connection. A subclass with
     * a table of its own shares no rows with its parent, and a key there
     * names something else.
     *
     * @param  class-string<Model>  $parent
     * @return array<string, class-string<Model>>
     */
    private function aliasesFor(string $parent): array
    {
        if (isset($this->subclassAliases[$parent])) {
            return $this->subclassAliases[$parent];
        }

        $base = new $parent;
        $aliases = [];

        foreach ($this->subclasses[$parent] ?? [] as $subclass) {
            $model = new $subclass;

            if ($model->getTable() === $base->getTable() && $model->getConnectionName() === $base->getConnectionName()) {
                $aliases[$model->getMorphClass()] = $subclass;
            }
        }

        return $this->subclassAliases[$parent] = $aliases;
    }

    /**
     * The deleted parent row as the subclass the feed knows it by, so the
     * tombstone asks the subclass's own entity what to keep.
     *
     * @param  class-string<Model>  $subclass
     */
    private function as(string $subclass, Model $row): Model
    {
        return (new $subclass)->newFromBuilder($row->getAttributes(), $row->getConnectionName());
    }

    /**
     * The registration for exactly this class, if any.
     *
     * @param  Model|class-string  $class
     * @return FeedableRegistration<Model>|null
     */
    public function registration(Model|string $class): ?FeedableRegistration
    {
        return $this->registrations[$class instanceof Model ? $class::class : $class] ?? null;
    }

    /** @param  Model|class-string|null  $class */
    public function isFeedable(Model|string|null $class): bool
    {
        return $class !== null
            && (is_a($class, Feedable::class, true) || $this->registration($class) !== null);
    }

    /** @return list<class-string<Model>> */
    public function registered(): array
    {
        return array_keys($this->registrations);
    }

    /** The stored half, from the class or from its registration. */
    public function toFeed(Model $model): FeedEntity
    {
        if ($model instanceof Feedable) {
            return $model->toFeed();
        }

        return ($this->registration($model) ?? throw new LogicException(
            'Model ['.$model::class.'] is not Feedable: implement the contract or register it with Storyfeed::feedable().'
        ))->toFeed($model);
    }

    /**
     * The read-time half. Null for a class that is not Feedable at all.
     *
     * @param  class-string  $class
     */
    public function feedMedia(string $class, FeedContext $context): ?FeedMedia
    {
        if (is_a($class, Feedable::class, true)) {
            return $class::feedMedia($context);
        }

        return $this->registration($class)?->feedMedia($context);
    }

    /**
     * Keep a model's `feedMediaUsing()` closure. A second registration for
     * the same class REPLACES the first, so a model booted twice (after
     * `Model::clearBootedModels()`) still has one resolver.
     *
     * @param  class-string<Model>  $class
     * @param  Closure(FeedContext, FeedMedia): (FeedMedia|string|null)  $resolver
     */
    public function useMediaResolver(string $class, Closure $resolver): void
    {
        $this->mediaResolvers[$class] = $resolver;
    }

    /**
     * @param  class-string<Model>  $class
     * @return (Closure(FeedContext, FeedMedia): (FeedMedia|string|null))|null
     */
    public function mediaResolver(string $class): ?Closure
    {
        return $this->mediaResolvers[$class] ?? null;
    }

    /**
     * Call a `feedMediaUsing()` closure: a string is the URL, a FeedMedia is
     * used as it is, and null means not linkable.
     *
     * @param  Closure(FeedContext, FeedMedia): (FeedMedia|string|null)  $resolver
     */
    public static function resolveMedia(Closure $resolver, FeedContext $context): ?FeedMedia
    {
        $media = FeedMedia::make();
        $resolved = $resolver($context, $media);

        return is_string($resolved) ? $media->url($resolved) : $resolved;
    }

    /** @param  (Closure(Model): ?string)|null  $guesser */
    public function guessLabelsUsing(?Closure $guesser): void
    {
        $this->labelGuesser = $guesser;
    }

    /**
     * A label for a model whose feed code set none: the app-wide guesser if
     * it answers, then the ladder — a `name` or `title` attribute, the
     * registered noun and the key ("Dish #42"), the class name and the key
     * ("Menu Item #42").
     */
    public function guessLabel(Model $model): string
    {
        if ($this->labelGuesser !== null && ($label = ($this->labelGuesser)($model)) !== null) {
            return $label;
        }

        foreach (['name', 'title'] as $attribute) {
            if (($label = $this->attributeLabel($model, $attribute)) !== null) {
                return $label;
            }
        }

        $noun = app(StoryfeedManager::class)->registeredNouns()[$model->getMorphClass()] ?? null;

        $words = $noun === null
            ? Str::headline(class_basename($model))
            : Str::ucfirst(FeedNoun::form($noun, 1));

        return $model->getKey() === null ? $words : "{$words} #{$model->getKey()}";
    }

    /**
     * An attribute's value when the model has one to give — a column that
     * was selected, or an accessor — without tripping
     * `Model::preventAccessingMissingAttributes()` on a model that has
     * neither.
     */
    private function attributeLabel(Model $model, string $attribute): ?string
    {
        if (! array_key_exists($attribute, $model->getAttributes())
            && ! $model->hasGetMutator($attribute)
            && ! $model->hasAttributeGetMutator($attribute)) {
            return null;
        }

        $value = $model->getAttribute($attribute);

        if (! (is_string($value) || is_int($value) || is_float($value) || $value instanceof Stringable)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
