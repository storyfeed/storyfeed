<?php

namespace Storyfeed\Actions;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\MorphResolver;

/**
 * Snapshot pass: refreshes entities referenced by activity roles and backfills
 * the cached FK columns.
 *
 * TWO MODES, AND THE SECOND IS THE ONE A DEPLOY RUNS.
 *
 * Unbounded — the original — iterates every DISTINCT entity referenced by any
 * role. Heavy by design: the backfill tool, not the scheduled worker (that is
 * {@see TrickleSnapshots}).
 *
 * BOUNDED BY ACTIVITIES SCANNED, NOT ENTITIES FOUND. `toFeed()` output is
 * cached, so changing that method changes what NEW snapshots store and leaves
 * every written row saying what it said before. A consumer edits, deploys,
 * looks, and sees nothing — which cost two sessions an hour of stylesheet
 * forensics before anyone asked what the row actually contained.
 *
 * So a deploy compiles them, the way a deploy compiles assets. The bound is a
 * count of the most recent ACTIVITIES, because "recompile recent entities" is a
 * distinct-across-seven-roles query ordered by recency and is neither cheap nor
 * predictable, while "the last thousand activities" costs what the operator
 * chose. A thousand might yield three hundred entities; ten thousand might
 * yield four hundred.
 *
 * NEWEST FIRST, and that is the opposite of the trickle's order on purpose. The
 * trickle rotates oldest-first so nothing starves. A deploy fixes what somebody
 * is about to look at, so it starts at the top of the feed and works back —
 * oldest-first would repair the archive while the homepage stayed wrong, which
 * is the same confusion arriving slower.
 */
class RebuildSnapshots
{
    public const ROLES = ActivityRoles::STORED;

    /**
     * @return array{snapshotted: int, missing: int}
     */
    /**
     * @param  int|null  $recentActivities  bound the pass to the entities named by
     *                                      this many of the newest activities;
     *                                      null is the exhaustive backfill
     * @return array{snapshotted: int, missing: int}
     */
    public function __invoke(?int $recentActivities = null): array
    {
        if ($recentActivities !== null) {
            return $this->recent($recentActivities);
        }

        $snapshotted = 0;
        $missing = 0;

        foreach (self::ROLES as $role) {
            // toBase(): these rows are (type, id) tuples, not Activity models.
            // Hydrating them would alias the ROLE's id onto Activity's primary
            // key, where Eloquent casts it to the model's int keyType — so a
            // string-keyed actor came back as 1 and the update below stamped
            // whichever row happened to hold that id.
            $pairs = $this->activityQuery()
                ->whereNotNull("{$role}_type")
                ->toBase()
                ->distinct()
                ->get(["{$role}_type as type", "{$role}_id as id"]);

            foreach ($pairs as $pair) {
                $model = $this->resolve($pair->type, $pair->id);

                if ($model === null) {
                    $missing++;

                    continue;
                }

                $snapshot = (new SnapshotEntity)($model);

                $this->activityQuery()
                    ->where("{$role}_type", $pair->type)
                    ->where("{$role}_id", $pair->id)
                    ->update(["cached_{$role}_id" => $snapshot->getKey()]);

                $snapshotted++;
            }
        }

        return ['snapshotted' => $snapshotted, 'missing' => $missing];
    }

    /**
     * The bounded pass.
     *
     * Ordered by the window, so an entity is snapshotted the first time it is
     * met and never again in the same run — unlike the exhaustive pass, which
     * walks role by role and re-snapshots an entity that appears in two of
     * them. Inside a deploy step that repetition is waste; across a backfill it
     * is harmless, and changing it there would change counts a test asserts.
     *
     * @return array{snapshotted: int, missing: int}
     */
    protected function recent(int $activities): array
    {
        $columns = [];

        foreach (self::ROLES as $role) {
            $columns[] = "{$role}_type";
            $columns[] = "{$role}_id";
        }

        // `published_at` is the feed's own order and is indexed; the id breaks
        // ties so a window is stable between two runs on the same data.
        $window = $this->activityQuery()
            ->toBase()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit($activities)
            ->get($columns);

        /** @var array<string, list<int|string>> $byType keyed alias => ids, in the order first met */
        $byType = [];
        /** @var list<array{role: string, type: string, id: int|string}> $pairs */
        $pairs = [];
        $seen = [];

        foreach ($window as $row) {
            foreach (self::ROLES as $role) {
                $type = $row->{"{$role}_type"} ?? null;
                $id = $row->{"{$role}_id"} ?? null;

                if (! is_string($type) || $type === '' || $id === null) {
                    continue;
                }

                $key = $type.'|'.$id;

                if (isset($seen[$key.'|'.$role])) {
                    continue;
                }

                $seen[$key.'|'.$role] = true;
                $pairs[] = ['role' => $role, 'type' => $type, 'id' => $id];

                if (! in_array($id, $byType[$type] ?? [], false)) {
                    $byType[$type][] = $id;
                }
            }
        }

        // ONE QUERY PER ALIAS, not one per entity. Three hundred round trips
        // inside a deploy step would make the bound a workaround for a query
        // pattern rather than a statement about cost.
        $models = [];

        foreach ($byType as $type => $ids) {
            $models[$type] = MorphResolver::feedables($type, $ids);
        }

        $snapshots = [];
        $snapshotted = 0;
        $missing = 0;

        foreach ($pairs as $pair) {
            $key = $pair['type'].'|'.$pair['id'];

            if (! array_key_exists($key, $snapshots)) {
                $model = $models[$pair['type']][(string) $pair['id']] ?? null;

                if ($model === null) {
                    $snapshots[$key] = null;
                    $missing++;

                    continue;
                }

                $snapshots[$key] = (new SnapshotEntity)($model);
                $snapshotted++;
            }

            $snapshot = $snapshots[$key];

            if ($snapshot === null) {
                continue;
            }

            // Every activity naming this entity in this role, not only the ones
            // inside the window: the snapshot they point at has just moved.
            $this->activityQuery()
                ->where("{$pair['role']}_type", $pair['type'])
                ->where("{$pair['role']}_id", $pair['id'])
                ->update(["cached_{$pair['role']}_id" => $snapshot->getKey()]);
        }

        return ['snapshotted' => $snapshotted, 'missing' => $missing];
    }

    /**
     * @return (Model&Feedable)|null
     */
    protected function resolve(string $type, int|string $id): ?Model
    {
        return MorphResolver::feedable($type, $id);
    }

    /** @return ActivityBuilder<Activity> */
    protected function activityQuery()
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return $model::query();
    }
}
