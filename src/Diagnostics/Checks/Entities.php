<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\MorphResolver;
use Throwable;

/**
 * A model that fills a feed role and cannot be resolved — named row by row,
 * with the reason (todo 439, from the operations-portal consumer).
 *
 * THE MISSING DIRECTION. `surface` checks a model that IMPLEMENTS Feedable
 * and never appears. Nothing checked the mirror: a model that DOES appear in
 * a role and implements nothing. The sharpest case is the host app's own
 * User — the package fills the actor role from the authenticated user
 * automatically, so it is the one model an integrator never wires up by
 * hand and never gets told about. A consumer found every action their
 * operator had taken rendering degraded in their own audit vault, and the
 * trickle counting the rows as unresolved on every run, forever.
 *
 * WHY A NAME AND NOT A COUNT. "8 entities, 1 missing" is accurate and
 * unactionable. A count says something is wrong; a name says it is your
 * User. `backlog` and the trickle report the count; this check reports the
 * role, the alias, the class it resolves to, why it does not resolve, and
 * example activities to go and look at — the shape the consumer had already
 * built for themselves and offered upstream.
 *
 * FOUR CAUSES, FOUR CODES, because the fix differs each time:
 *
 *   entities.unresolvable   the alias resolves to no class at all — no morph
 *                           map entry, and no class by that name;
 *   entities.not_model      it resolves to a class that is not Eloquent;
 *   entities.unfeedable     it resolves to a model with no `Feedable`;
 *   entities.missing        the model is Feedable and the row is gone, or
 *                           hidden by a global scope.
 *
 * The first three are properties of an alias and cost one distinct query per
 * role. The last is a property of a row, so it is bounded: a sample of the
 * uncached ids per (role, alias), one whereKey() per class, never a scan.
 * A row that is present and uncached is not this check's business — that is
 * the trickle's backlog and `backlog` already counts it.
 *
 * SAME VERDICT AS THE TRICKLE. "Cannot be resolved" is what
 * `MorphResolver::feedable()` answers null to, so the rows named here are
 * exactly the rows the trickle counts, and would delete with pruning on.
 * A soft-deleted row is therefore reported: the default scope hides it from
 * the resolver too, and saying so is more useful than pretending the row
 * is fine because it is technically still there.
 *
 * THE AUTH MODEL IS CHECKED BEFORE ANY TRAFFIC. It needs no table and no
 * activity: if the configured authentication model is not Feedable, every
 * request-time publish is about to carry an actor that never resolves, and
 * the day to say so is the first run of doctor, not the day after go-live.
 * Skipped when an actor resolver is configured, because then the auth model
 * may never be the actor; a runtime `resolveActorUsing()` closure is not
 * visible here, and the recorded-alias pass catches its choice once it runs.
 *
 * Warning, not Error: nothing throws, and activities are never withheld.
 * But every one of those rows renders without a label or a link, for as long
 * as the contract is missing, and nobody was being told why.
 */
class Entities extends Check
{
    /** Activities quoted per finding — enough to go and look at, never a listing. */
    protected const EXAMPLES = 3;

    /** Uncached ids inspected per (role, alias) for a row that is gone. */
    protected const SAMPLE = 50;

    public function name(): string
    {
        return 'entities';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        yield from $this->authModel();

        if (! $this->hasTable('activities')) {
            return;
        }

        foreach (SyncParticipants::ROLES as $role) {
            foreach ($this->recordedAliases($role) as $alias) {
                $class = MorphResolver::classFor($alias);
                $count = $this->activities()->where("{$role}_type", $alias)->count();
                $examples = $this->examples($role, $alias);
                $subject = ['role' => $role, 'type' => $alias, 'class' => $class, 'activities' => $count, 'examples' => $examples];

                if ($class === null) {
                    yield Finding::warning(
                        'entities.unresolvable',
                        "`{$alias}` fills the {$role} role on {$count} ".str('activity')->plural($count)
                        ." (e.g. {$examples}) but resolves to no class: there is no morph map entry for it and no "
                        .'class by that name. Those rows render without a label or a link, and the trickle counts '
                        .'them as unresolved on every run. Register the alias in the morph map, or restore the class.',
                        $subject,
                    );

                    continue;
                }

                if (! is_a($class, Model::class, true)) {
                    yield Finding::warning(
                        'entities.not_model',
                        "`{$alias}` fills the {$role} role on {$count} ".str('activity')->plural($count)
                        ." (e.g. {$examples}) and resolves to [{$class}], which is not an Eloquent model, so it can "
                        .'never be snapshotted. Those rows render without a label or a link.',
                        $subject,
                    );

                    continue;
                }

                if (! is_a($class, Feedable::class, true)) {
                    yield Finding::warning(
                        'entities.unfeedable',
                        "[{$class}] (`{$alias}`) fills the {$role} role on {$count} ".str('activity')->plural($count)
                        ." (e.g. {$examples}) but does not implement Feedable, so it is never snapshotted: every one "
                        .'of those rows renders without a label or a link for as long as the contract is missing, '
                        .'and the trickle counts them as unresolved on every run. Implement Feedable '
                        .'(`use InteractsWithFeed` and write toFeed()), then run storyfeed:trickle.',
                        $subject,
                    );

                    continue;
                }

                yield from $this->missing($role, $alias, $class);
            }
        }
    }

    /**
     * The configured authentication model, before it has published anything.
     *
     * @return iterable<Finding>
     */
    protected function authModel(): iterable
    {
        if (config('storyfeed.actor_resolver')) {
            return;
        }

        $guard = config('auth.defaults.guard');
        $provider = config("auth.guards.{$guard}.provider");
        $model = config("auth.providers.{$provider}.model");

        if (! is_string($model) || ! class_exists($model) || ! is_a($model, Model::class, true)) {
            return;
        }

        if (is_a($model, Feedable::class, true)) {
            return;
        }

        yield Finding::warning(
            'entities.auth_model',
            "[{$model}] is the authentication model, and Storyfeed fills the actor role from the authenticated "
            .'user automatically — but it does not implement Feedable. Every activity published during a request '
            .'will carry an actor that never resolves: no snapshot, no label, no link, and the trickle counting it '
            .'as unresolved on every run. Implement Feedable on it (`use InteractsWithFeed` and write toFeed()) '
            .'before anything publishes.',
            ['model' => $model, 'guard' => is_string($guard) ? $guard : null],
        );
    }

    /**
     * Uncached rows of a Feedable class whose model is gone — or hidden by a
     * global scope, which resolves the same way. Sampled, never scanned.
     *
     * @param  class-string<Model>  $class
     * @return iterable<Finding>
     */
    protected function missing(string $role, string $alias, string $class): iterable
    {
        $ids = $this->activities()
            ->where("{$role}_type", $alias)
            ->whereNull("cached_{$role}_id")
            ->whereNotNull("{$role}_id")
            ->orderByDesc('published_at')
            ->limit(self::SAMPLE)
            ->toBase()
            ->pluck("{$role}_id")
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        try {
            $present = $class::query()->whereKey($ids->all())->toBase()
                ->pluck((new $class)->getKeyName())
                ->map(fn ($id) => (string) $id)
                ->all();
        } catch (Throwable $e) {
            yield Finding::info(
                'entities.opaque',
                "[{$class}] (`{$alias}`) could not be queried to see whether its {$role} rows still exist — "
                .$e::class.': '.$e->getMessage().' — so nothing can be said about them.',
                ['role' => $role, 'type' => $alias, 'class' => $class, 'exception' => $e::class],
            );

            return;
        }

        $gone = $ids->reject(fn ($id) => in_array((string) $id, $present, true))->values();

        if ($gone->isEmpty()) {
            return;
        }

        $named = $gone->take(self::EXAMPLES)->map(fn ($id) => "`{$alias}#{$id}`")->implode(', ');
        $more = $gone->count() > self::EXAMPLES ? ' and '.($gone->count() - self::EXAMPLES).' more' : '';
        $sampled = $ids->count() >= self::SAMPLE ? ' in the '.self::SAMPLE.' most recent uncached rows' : '';
        $examples = $this->examples($role, $alias, $gone->take(self::EXAMPLES)->all());

        yield Finding::warning(
            'entities.missing',
            "{$gone->count()} {$role} ".str('row')->plural($gone->count())." of [{$class}] "
            ."{$sampled} cannot be resolved: {$named}{$more} (e.g. {$examples}). The model is Feedable, but the "
            .'row is gone or hidden by a global scope, so those activities render without a label or a link and '
            .'the trickle counts them as unresolved on every run. If the records are genuinely gone, '
            .'`storyfeed:trickle --prune` retires the activities; if they are soft-deleted, restore them or accept '
            .'the degraded rows.',
            [
                'role' => $role,
                'type' => $alias,
                'class' => $class,
                'missing' => $gone->count(),
                'ids' => $gone->implode(', '),
                'examples' => $examples,
            ],
        );
    }

    /**
     * A few activity ids to go and look at, newest first.
     *
     * @param  list<int|string>  $ids  narrow to rows filling the role with these entity ids
     */
    protected function examples(string $role, string $alias, array $ids = []): string
    {
        $activities = $this->activities()
            ->where("{$role}_type", $alias)
            ->when($ids !== [], fn ($query) => $query->whereIn("{$role}_id", $ids))
            ->orderByDesc('published_at')
            ->limit(self::EXAMPLES)
            ->toBase()
            ->pluck('id');

        return $activities->map(fn ($id) => "activity #{$id}")->implode(', ');
    }
}
