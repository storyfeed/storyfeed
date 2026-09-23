<?php

namespace Storyfeed\Actions;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Stories\Verb;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\Chronology;

/**
 * Retire activities older than their verb's retention window. Strictly
 * opt-in: with no window configured, declared or given, nothing is deleted.
 *
 * A verb's own window (`->keepFor('30 days')`) wins over the global
 * `storyfeed.prune.after_days` in either direction; `->keepForever()`
 * exempts it; a verb that says nothing takes the global window, or is kept
 * when there is none. Resolved on the type → verb ladder like every rule a
 * verb declares, and for an activity whose object is a tombstone, with the
 * type the object was: deleting an order must not change how long its views
 * are kept.
 *
 * Deletes through PurgeActivities, in chunks, soft-deleted rows included
 * (superseded rows, healer retirements): what remains of each group is
 * repaired, and the snapshots and tombstones only the pruned rows named go
 * with them.
 *
 * Writes no evidence of what it removed, on purpose and now without
 * exception. A row past the retention window left because of a policy the
 * app configured, not because anyone decided about that row — so there is
 * nothing about it worth recording that the policy does not already say.
 *
 * It used to advance a retention watermark in `feed_meta` and leave a
 * per-key evidence table unswept. Both are gone: they existed so that
 * something could later tell a deliberate removal from an expiry, and
 * nothing in this package ever asked. An app that needs to know keeps its
 * own record, where it also knows why.
 */
class PruneActivities
{
    /**
     * @param  int|null  $days  overrides `storyfeed.prune.after_days`; never a verb's own window
     * @return array{enabled: bool, pruned: int, verbs: array<string, int>, snapshots: int, tombstones: int}
     */
    public function __invoke(?int $days = null, bool $pretend = false): array
    {
        $slices = $this->slices($days);

        if ($slices === null) {
            return ['enabled' => false, 'pruned' => 0, 'verbs' => [], 'snapshots' => 0, 'tombstones' => 0];
        }

        $query = fn () => $this->expired($slices);

        if ($pretend) {
            $result = (new PurgeActivities)->pretend($query);

            return ['enabled' => true, 'pruned' => array_sum($result['verbs']), ...$result];
        }

        // Counted first, per verb, only for the report: the purge itself
        // walks one combined query.
        $verbs = $slices === [] ? [] : $query()->toBase()
            ->select('verb')->selectRaw('count(*) as aggregate')
            ->groupBy('verb')->orderBy('verb')
            ->pluck('aggregate', 'verb')->map(fn ($count) => (int) $count)->all();

        $result = $verbs === []
            ? ['activities' => 0, 'snapshots' => 0, 'tombstones' => 0]
            : (new PurgeActivities)($query);

        return [
            'enabled' => true,
            'pruned' => $result['activities'],
            'verbs' => $verbs,
            'snapshots' => $result['snapshots'],
            'tombstones' => $result['tombstones'],
        ];
    }

    /**
     * The window each recorded verb is pruned on, per object type where a
     * declaration makes a type differ: null when no window exists anywhere.
     *
     * A slice with `types` null covers every type not in `except`, and
     * object-less rows; one with `verb` null, every verb.
     *
     * @return list<array{verb: string|null, window: string, types: list<string>|null, except: list<string>}>|null
     */
    public function slices(?int $days = null): ?array
    {
        $storyfeed = app(StoryfeedManager::class);
        $days ??= config('storyfeed.prune.after_days');
        $global = $days === null ? null : 'P'.(int) $days.'D';

        $declared = array_filter($storyfeed->storyRetention(), fn (string $window) => $window !== Verb::FOREVER);

        if ($global === null && $declared === []) {
            return null;
        }

        // Nothing declared: the plain age test, as before verbs had windows.
        if ($global !== null && $storyfeed->storyRetention() === []) {
            return [['verb' => null, 'window' => $global, 'types' => null, 'except' => []]];
        }

        $tombstone = $this->tombstoneAlias();

        $verbs = $this->activities()->withTrashed()->toBase()->distinct()->orderBy('verb')->pluck('verb')->all();

        // Every type a declaration could single out: those recorded, and
        // those a tombstoned object used to be.
        $types = array_values(array_unique([
            ...$this->activities()->withTrashed()->toBase()->whereNotNull('object_type')->where('object_type', '!=', $tombstone)
                ->distinct()->pluck('object_type')->all(),
            ...(TombstoneEntity::installed() ? $this->tombstones()->toBase()->distinct()->pluck('model_type')->all() : []),
        ]));

        $slices = [];

        foreach ($verbs as $verb) {
            $verb = (string) $verb;
            $rest = self::usable($storyfeed->retention(null, $verb) ?? $global);
            $except = [];

            foreach ($types as $type) {
                $window = self::usable($storyfeed->retention((string) $type, $verb) ?? $global);

                if ($window === $rest) {
                    continue;
                }

                $except[] = (string) $type;

                if ($window !== null) {
                    $slices[] = ['verb' => $verb, 'window' => $window, 'types' => [(string) $type], 'except' => []];
                }
            }

            if ($rest !== null) {
                $slices[] = ['verb' => $verb, 'window' => $rest, 'types' => null, 'except' => $except];
            }
        }

        return $slices;
    }

    /** The moment before which a window's rows are expired, as of now or `$asOf`. */
    public static function cutoff(string $window, ?CarbonInterface $asOf = null): CarbonInterface
    {
        return ($asOf ?? Carbon::now())->toImmutable()->sub(CarbonInterval::make($window) ?? CarbonInterval::days(0));
    }

    /**
     * Every row past its slice's window, trashed rows included: as of now,
     * or as of `$asOf` (what the doctor asks, a day back).
     *
     * @param  list<array{verb: string|null, window: string, types: list<string>|null, except: list<string>}>  $slices
     * @return ActivityBuilder<Activity>
     */
    public function expired(array $slices, ?CarbonInterface $asOf = null): ActivityBuilder
    {
        $tombstone = $this->tombstoneAlias();
        $tombstones = $this->tombstones();
        $formerly = fn (array $types) => $tombstones->newQuery()->toBase()
            ->select($tombstones->getModel()->getQualifiedKeyName())
            ->whereIn('model_type', $types);

        return $this->activities()->withTrashed()->where(function ($query) use ($slices, $tombstone, $formerly, $asOf) {
            if ($slices === []) {
                $query->whereRaw('1 = 0');
            }

            foreach ($slices as $slice) {
                $query->orWhere(function ($query) use ($slice, $tombstone, $formerly, $asOf) {
                    $query->when($slice['verb'] !== null, fn ($query) => $query->where('verb', $slice['verb']))
                        ->where('published_at', '<', Chronology::stamp(self::cutoff($slice['window'], $asOf)));

                    if ($slice['types'] !== null) {
                        $types = $slice['types'];

                        $query->where(fn ($query) => $query
                            ->whereIn('object_type', $types)
                            ->orWhere(fn ($query) => $query->where('object_type', $tombstone)->whereIn('object_id', $formerly($types))));

                        return;
                    }

                    if ($slice['except'] === []) {
                        return;
                    }

                    $except = $slice['except'];

                    // Spelled positively: `object_type NOT IN (…)` is never
                    // true for an object-less row, which is NULL there.
                    $query->where(fn ($query) => $query
                        ->whereNull('object_type')
                        ->orWhere(fn ($query) => $query->where('object_type', '!=', $tombstone)->whereNotIn('object_type', $except))
                        ->orWhere(fn ($query) => $query->where('object_type', $tombstone)->whereNotIn('object_id', $formerly($except))));
                });
            }
        });
    }

    /** A window a prune applies: null for none, and for `forever`. */
    protected static function usable(?string $window): ?string
    {
        return $window === Verb::FOREVER ? null : $window;
    }

    /** @return ActivityBuilder<Activity> */
    protected function activities(): ActivityBuilder
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return $model::query();
    }

    /** @return Builder<FeedTombstone> */
    protected function tombstones()
    {
        $model = config('storyfeed.models.tombstone', FeedTombstone::class);

        return $model::query();
    }

    protected function tombstoneAlias(): string
    {
        $model = config('storyfeed.models.tombstone', FeedTombstone::class);

        return (new $model)->getMorphClass();
    }
}
