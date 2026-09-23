<?php

namespace Storyfeed\Actions;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\SyncToken;

/**
 * Permanently delete the activities a query selects, and leave nothing
 * behind that only they needed. What a prune run does to the rows past
 * their verb's window, and what a verb's `->forgetWhenMissing()` does to
 * its redundant rows, so the two leave the same state behind.
 *
 * On top of ForceDeleteFromFeed's per-chunk bookkeeping (grouping and
 * participant rows, in one transaction with the delete):
 *
 *  - A composite whose parent goes releases its members back to inference,
 *    as ReleaseComposite does for one force-deleted parent. A bulk delete
 *    fires no model events, and a member still claimed by a parent that no
 *    longer exists is never solo and never in a group: the read path would
 *    hide it.
 *  - Every curated cluster a deleted row belonged to is repaired once, at
 *    the end (CurateCluster::repairMany), so a group reads as what remains:
 *    "Sally viewed 12 orders" with 9 pruned reads "Sally viewed 3 orders".
 *    A cluster with nothing left has no grouping rows left either, so no
 *    group node can outlive its members. `sync_token` moves once when a
 *    group shrank or went.
 *  - A snapshot no remaining activity references in any role is deleted,
 *    and so is a tombstone. Only the entities the deleted rows referenced
 *    are candidates: a snapshot is written on every save, whether or not an
 *    activity ever names it, so a table-wide sweep would delete snapshots
 *    this run had nothing to do with. They come back on the entity's next
 *    save or publish.
 *
 * THE ORDER IS THE TRAP. The candidates are read from the rows before they
 * are deleted, because deleting them takes their participant rows with them,
 * and the check that a candidate is still referenced runs after, against the
 * activities table itself (soft-deleted rows included: a restore can bring
 * one back). Checked against participants, a superseded soft-deleted row has
 * none, and an entity it still names would read as orphaned.
 */
class PurgeActivities
{
    public const CHUNK = 500;

    /**
     * @param  Closure(): ActivityBuilder<Activity>  $query  a fresh query each pass, trashed rows included if wanted
     * @return array{activities: int, snapshots: int, tombstones: int, groups: int}
     */
    public function __invoke(Closure $query): array
    {
        /** @var array<string, array{0: string, 1: string, 2: int}> $clusters axis\0hash => [axis, hash, members deleted] */
        $clusters = [];
        /** @var array<string, array<string, true>> $entities type => [id => true] */
        $entities = [];
        $forget = new ForgetActivities;
        $deleted = 0;

        while (true) {
            $ids = $query()->limit(self::CHUNK)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $this->activities()->getConnection()->transaction(function () use ($ids, $forget, &$clusters, &$entities) {
                $this->releaseComposites($ids);
                $this->collectClusters($ids, $clusters);
                $this->collectEntities($this->activities()->withTrashed()->whereKey($ids), $entities);

                $forget(...$ids);

                $this->activities()->withTrashed()->whereKey($ids)->forceDelete();
            });

            $deleted += count($ids);
        }

        if ($deleted === 0) {
            return ['activities' => 0, 'snapshots' => 0, 'tombstones' => 0, 'groups' => 0];
        }

        $groups = $this->repair($clusters);

        [$snapshots, $tombstones] = $this->sweep($entities);

        if ($groups > 0) {
            SyncToken::bump();
        }

        return ['activities' => $deleted, 'snapshots' => $snapshots, 'tombstones' => $tombstones, 'groups' => $groups];
    }

    /**
     * What the same query would delete, without deleting anything: the rows
     * per verb, and the snapshots and tombstones nothing else references.
     *
     * @param  Closure(): ActivityBuilder<Activity>  $query
     * @return array{verbs: array<string, int>, snapshots: int, tombstones: int}
     */
    public function pretend(Closure $query): array
    {
        $verbs = $query()->toBase()
            ->select('verb')->selectRaw('count(*) as aggregate')
            ->groupBy('verb')->orderBy('verb')
            ->pluck('aggregate', 'verb')
            ->map(fn ($count) => (int) $count)
            ->all();

        if ($verbs === []) {
            return ['verbs' => [], 'snapshots' => 0, 'tombstones' => 0];
        }

        $entities = [];
        $this->collectEntities($query(), $entities);

        $doomed = $query()->toBase()->select($this->activities()->getModel()->qualifyColumn('id'));
        $snapshots = 0;
        $tombstones = 0;

        foreach ($entities as $type => $ids) {
            foreach (array_chunk(array_keys($ids), self::CHUNK) as $chunk) {
                $snapshots += $this->orphanedSnapshots($type, $chunk, $doomed)->count();

                if ($type === $this->tombstoneAlias()) {
                    $tombstones += $this->orphanedTombstones($chunk, $doomed)->count();
                }
            }
        }

        return ['verbs' => $verbs, 'snapshots' => $snapshots, 'tombstones' => $tombstones];
    }

    /**
     * Release the members of every composite whose parent is in this chunk,
     * before its claim rows go with it.
     *
     * @param  list<int|string>  $ids
     */
    protected function releaseComposites(array $ids): void
    {
        $claims = $this->groupings()->where('bucket', 'composite')->whereIn('activity_id', $ids)->pluck('hash', 'activity_id');

        if ($claims->isEmpty()) {
            return;
        }

        $release = new ReleaseComposite;
        $model = config('storyfeed.models.activity', Activity::class);

        foreach ($this->activities()->withTrashed()->whereKey($claims->keys()->all())->toBase()->get(['id', 'uid']) as $row) {
            // A parent's own claim is keyed by its uid; a member's is not.
            if ($claims[$row->id] !== $row->uid) {
                continue;
            }

            // Not saved and not existing: what ReleaseComposite reads as forced.
            $parent = (new $model)->forceFill(['id' => $row->id, 'uid' => $row->uid]);

            $release($parent);
        }
    }

    /**
     * @param  list<int|string>  $ids
     * @param  array<string, array{0: string, 1: string, 2: int}>  $clusters
     */
    protected function collectClusters(array $ids, array &$clusters): void
    {
        $rows = $this->groupings()
            ->whereIn('activity_id', $ids)
            ->whereNotIn('bucket', app(StoryfeedManager::class)->rowBackedBuckets())
            ->toBase()
            ->get(['bucket', 'hash']);

        foreach ($rows as $row) {
            $key = "{$row->bucket}\0{$row->hash}";
            $clusters[$key] ??= [(string) $row->bucket, (string) $row->hash, 0];
            $clusters[$key][2]++;
        }
    }

    /**
     * Every entity the selected rows name, in any role.
     *
     * @param  ActivityBuilder<Activity>  $rows
     * @param  array<string, array<string, true>>  $entities
     */
    protected function collectEntities(ActivityBuilder $rows, array &$entities): void
    {
        $id = $rows->getModel()->getQualifiedKeyName();
        $columns = [$id];

        foreach (ActivityRoles::STORED as $role) {
            $columns[] = "{$role}_type";
            $columns[] = "{$role}_id";
        }

        $rows->toBase()->select($columns)->chunkById(self::CHUNK, function ($chunk) use (&$entities) {
            foreach ($chunk as $row) {
                foreach (ActivityRoles::STORED as $role) {
                    if ($row->{"{$role}_type"} !== null && $row->{"{$role}_id"} !== null) {
                        $entities[(string) $row->{"{$role}_type"}][(string) $row->{"{$role}_id"}] = true;
                    }
                }
            }
        }, $id, 'id');
    }

    /**
     * Re-decide what remains of each cluster, and count the groups that
     * changed: a cluster that had two or more members before this run.
     *
     * @param  array<string, array{0: string, 1: string, 2: int}>  $clusters
     */
    protected function repair(array $clusters): int
    {
        if ($clusters === []) {
            return 0;
        }

        $remaining = [];

        foreach (array_chunk(array_values($clusters), self::CHUNK) as $chunk) {
            $rows = $this->groupings()
                ->where(function ($query) use ($chunk) {
                    foreach ($chunk as [$axis, $hash]) {
                        $query->orWhere(fn ($query) => $query->where('bucket', $axis)->where('hash', $hash));
                    }
                })
                ->toBase()
                ->select('bucket', 'hash')->selectRaw('count(*) as aggregate')
                ->groupBy('bucket', 'hash')
                ->get();

            foreach ($rows as $row) {
                $remaining["{$row->bucket}\0{$row->hash}"] = (int) $row->aggregate;
            }
        }

        $groups = 0;

        foreach ($clusters as $key => [, , $deleted]) {
            if ($deleted + ($remaining[$key] ?? 0) >= 2) {
                $groups++;
            }
        }

        $survivors = array_values(array_map(
            fn (array $cluster) => [$cluster[0], $cluster[1]],
            array_intersect_key($clusters, $remaining),
        ));

        if ($survivors !== [] && config('storyfeed.grouping.curate', true)) {
            (new CurateCluster)->repairMany($survivors);
        }

        return $groups;
    }

    /**
     * Delete the candidates' snapshots, and tombstones, that nothing still
     * references.
     *
     * @param  array<string, array<string, true>>  $entities  type => [id => true]
     * @return array{0: int, 1: int} snapshots, tombstones
     */
    public function sweep(array $entities): array
    {
        $snapshots = 0;
        $tombstones = 0;

        foreach ($entities as $type => $ids) {
            foreach (array_chunk(array_keys($ids), self::CHUNK) as $chunk) {
                if ($type === $this->tombstoneAlias()) {
                    $tombstones += $this->orphanedTombstones($chunk)->delete();
                }

                $snapshots += $this->orphanedSnapshots($type, $chunk)->delete();
            }
        }

        return [$snapshots, $tombstones];
    }

    /**
     * @param  list<string>  $ids
     */
    protected function orphanedSnapshots(string $type, array $ids, ?QueryBuilder $excluding = null): QueryBuilder
    {
        $model = config('storyfeed.models.snapshot', Snapshot::class);
        $table = (new $model)->getTable();

        return $this->unreferenced(
            $model::query()->toBase()->where("{$table}.model_type", $type)->whereIn("{$table}.model_id", $ids),
            $type,
            "{$table}.model_id",
            $excluding,
        );
    }

    /**
     * @param  list<string>  $ids
     */
    protected function orphanedTombstones(array $ids, ?QueryBuilder $excluding = null): QueryBuilder
    {
        $model = config('storyfeed.models.tombstone', FeedTombstone::class);
        $tombstone = new $model;

        return $this->unreferenced(
            $model::query()->toBase()->whereIn($tombstone->getQualifiedKeyName(), $ids),
            $tombstone->getMorphClass(),
            $tombstone->getQualifiedKeyName(),
            $excluding,
        );
    }

    /**
     * Constrain a query over an entity table to the rows no activity names in
     * any role, trashed activities included. `$excluding` (a query of activity
     * ids) is left out of the count: what `pretend()` treats as already gone.
     */
    protected function unreferenced(QueryBuilder $query, string $type, string $idColumn, ?QueryBuilder $excluding): QueryBuilder
    {
        $activities = $this->activities()->getModel()->getTable();

        foreach (ActivityRoles::STORED as $role) {
            $query->whereNotExists(function (QueryBuilder $sub) use ($activities, $role, $type, $idColumn, $excluding) {
                $sub->selectRaw('1')
                    ->from($activities, 'referencing')
                    ->where("referencing.{$role}_type", $type)
                    ->whereColumn("referencing.{$role}_id", $idColumn);

                if ($excluding !== null) {
                    $sub->whereNotIn('referencing.id', $excluding);
                }
            });
        }

        return $query;
    }

    protected function tombstoneAlias(): string
    {
        $model = config('storyfeed.models.tombstone', FeedTombstone::class);

        return (new $model)->getMorphClass();
    }

    /** @return ActivityBuilder<Activity> */
    protected function activities(): ActivityBuilder
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return $model::query();
    }

    /** @return Builder<Grouping> */
    protected function groupings()
    {
        $model = config('storyfeed.models.grouping', Grouping::class);

        return $model::query();
    }
}
