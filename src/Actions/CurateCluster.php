<?php

namespace Storyfeed\Actions;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;
use Storyfeed\Events\Snapshots\ActivitySnapshot;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\Grouping;
use Storyfeed\StoryfeedManager;

/**
 * Curation: choose the ONE axis an activity is grouped on, and stamp it
 * `winner` (docs/grouping.md).
 *
 * The policy is **distinct cardinality on the dimension each axis
 * collapses** — not "the largest cluster past a threshold", which is a coin
 * flip: Sally's three uploads produce a `repeat` cluster of 3 AND a `targets`
 * cluster of 3, and the wrong winner turns "Sally uploaded 3 files" into
 * "Sally uploaded to 1 project".
 *
 *   actors  wins on distinct actors  >= min_actors  (default 3)
 *   targets wins on distinct targets >= min_targets (default 2), with
 *           at least min_target_members members
 *   object  wins on the same object acted on >= min_object_members times
 *           (default 2) — "Bob made 5 revisions to Aut Beatae.docx"
 *   else    repeat (a cluster of one renders as a plain activity node)
 *
 * The function is pure with respect to the database: same rows in, same
 * stamps out, so it is safe to re-run (`storyfeed:curate`) and safe to call
 * inline from the publish transaction.
 *
 * Cost is amortized O(1). A publish touches only the <= 3 clusters it emits
 * hashes for; within a day clusters only grow, so winners are monotone and
 * the O(cluster) resettle sweep runs only when a threshold is actually
 * crossed — after which every member is already stamped and the sweep finds
 * nothing to do.
 */
class CurateCluster
{
    /** @param (Closure(bool): void)|null $onSettled Optional maintenance accounting; absent on the publish path. */
    /**
     * Cluster eligibility decided during one repairMany() pass; null outside
     * one, when nothing is remembered.
     *
     * @var array<string, bool>|null
     */
    private ?array $eligibility = null;

    public function __construct(protected ?Closure $onSettled = null) {}

    /**
     * Curate one activity and settle any cluster it just tipped over a
     * threshold.
     */
    public function __invoke(Activity $activity): void
    {
        $hashes = $this->hashes($activity->getKey());

        if ($hashes === []) {
            return;
        }

        $this->settle($activity->getKey(), $hashes);

        $this->resettle($hashes);
    }

    /**
     * A deletion can drop a cluster back below its threshold, so the
     * remaining members must be re-decided — the one case where winners are
     * not monotone.
     *
     * Every member is settled directly, NOT through resettle(). This is not
     * redundancy to tidy away: resettle()'s staleness predicate treats a
     * member whose winner outranks the axis as correctly settled, which is
     * only true while clusters grow. After a delete a survivor stamped for
     * the highest axis can be exactly the row that no longer earns it, and
     * the sweep would never select it.
     */
    public function afterDelete(Activity|ActivitySnapshot $activity): void
    {
        $hashes = $this->hashes($activity->id);

        // Event listeners use the captured flag; direct model callers retain
        // the existing transient-flag/exists behavior.
        $forced = ($activity instanceof ActivitySnapshot ? $activity->forceDeleted : $activity->isForceDeleting() || ! $activity->exists);

        // A force-deleted activity can never come back, so its candidate
        // hashes are orphans — the same cleanup PruneActivities does.
        // Deleted by key for the reason settle() writes by key.
        if ($forced) {
            $orphans = $this->groupings()->where('activity_id', $activity->id)->pluck($this->groupingKey())->all();

            if ($orphans !== []) {
                $this->groupings()->whereKey($orphans)->delete();
            }
        }

        foreach ($hashes as $axis => $hash) {
            foreach ($this->memberIds($axis, $hash) as $id) {
                if ($id !== $activity->id) {
                    $this->settle($id, $this->hashes($id));
                }
            }
        }

        // A soft-deleted activity keeps its rows, and may be restored, so it
        // is re-decided like any other member.
        if (! $forced) {
            $this->settle($activity->id, $hashes);
        }
    }

    /**
     * Decide and stamp one activity from its own candidate hashes.
     *
     * Compares before writing: the decision is a pure function of the rows,
     * so when the stamps already say what it says there is nothing to do,
     * and a settle that changes nothing costs one read instead of two writes
     * (and, under maintenance accounting, a second read). On a settled
     * cluster that is every settle but the new member's own.
     *
     * The writes go by primary key, never by `activity_id`: on a small
     * table InnoDB may scan rather than use the index, and a locking scan
     * takes every row it passes — including a concurrent publish's
     * uncommitted rows, which is a deadlock between two publishes that share
     * nothing (MariaDB, todo 1349). A row gone between the read and the
     * write is simply not updated.
     *
     * @param  array<string, string>  $hashes  bucket => hash
     * @param  array<string, array{int|string, bool|null}>|null  $stamps  bucket => [id, winner], when the caller already read them (see winnerState())
     */
    protected function settle(int|string $activityId, array $hashes, ?array $stamps = null): void
    {
        if ($hashes === []) {
            return;
        }

        $winner = $this->decide($hashes);
        $stamps ??= $this->winnerState($activityId);
        $before = array_map(fn (array $stamp) => $stamp[1], $stamps);
        $after = array_map(fn (string $bucket) => $bucket === $winner, array_combine(array_keys($before), array_keys($before)));

        $changed = $before !== $after;

        if ($changed) {
            $ids = array_map(fn (array $stamp) => $stamp[0], $stamps);
            $losers = array_values(array_diff_key($ids, [$winner => true]));

            DB::transaction(function () use ($ids, $losers, $winner) {
                // Cleared first, so there is never a moment with two winners.
                // Batch rows stay winner = null — they are outside curation,
                // and never among the stamps.
                if ($losers !== []) {
                    $this->groupings()->whereKey($losers)->update(['winner' => false]);
                }

                if (isset($ids[$winner])) {
                    $this->groupings()->whereKey($ids[$winner])->update(['winner' => true]);
                }
            });
        }

        if ($this->onSettled !== null) {
            ($this->onSettled)($changed);
        }
    }

    /**
     * The activity's current stamps and the keys to write them by,
     * normalised: drivers return the winner column as int, bool or null and
     * the comparison in settle() must not care which.
     *
     * @return array<string, array{int|string, bool|null}> bucket => [id, winner]
     */
    protected function winnerState(int|string $activityId): array
    {
        return $this->stamps($this->groupings()->where('activity_id', $activityId)
            ->whereNotIn('bucket', $this->manager()->rowBackedBuckets())
            ->orderBy('bucket')
            ->toBase()
            ->get([$this->groupingKey(), 'bucket', 'winner']));
    }

    /**
     * @param  Collection<int, stdClass>  $rows  one activity's grouping rows, ordered by bucket
     * @return array<string, array{int|string, bool|null}> bucket => [id, winner]
     */
    protected function stamps(Collection $rows): array
    {
        $key = $this->groupingKey();

        return $rows
            ->mapWithKeys(fn (stdClass $row) => [$row->bucket => [$row->{$key}, $row->winner === null ? null : (bool) $row->winner]])
            ->all();
    }

    /**
     * @param  array<string, string>  $hashes  bucket => hash
     */
    protected function decide(array $hashes): string
    {
        // Registration order IS priority (docs/grouping.md).
        foreach ($this->manager()->aggregateAxes() as $axis) {
            if (isset($hashes[$axis]) && $this->eligible($axis, $hashes[$axis])) {
                return $axis;
            }
        }

        $fallback = $this->manager()->fallbackAxis()?->name;

        return $fallback !== null && isset($hashes[$fallback])
            ? $fallback
            : (string) array_key_first($hashes);
    }

    /**
     * Interpret the axis's declarative eligibility rules (all must pass).
     * The rules are data, not closures — introspectable, and interpreted
     * against this action's cluster queries.
     */
    protected function eligible(string $axis, string $hash): bool
    {
        if ($this->eligibility === null) {
            return $this->decideEligible($axis, $hash);
        }

        return $this->eligibility["{$axis}\0{$hash}"] ??= $this->decideEligible($axis, $hash);
    }

    protected function decideEligible(string $axis, string $hash): bool
    {
        $declaration = $this->manager()->axis($axis);

        if ($declaration === null || $declaration->eligibility() === []) {
            return false;
        }

        foreach ($declaration->eligibility() as $rule) {
            $passes = isset($rule['distinct'])
                ? $this->distinctRoles($axis, $hash, $rule['distinct']) >= ($rule['min'] ?? 1)
                : $this->clusterActivities($axis, $hash)->count() >= ($rule['members'] ?? 1);

            if (! $passes) {
                return false;
            }
        }

        return true;
    }

    /**
     * Re-decide every remaining member of one cluster — for callers that
     * removed members out-of-band (composite claiming, releases). The same
     * non-monotone repair a deletion triggers, and for the same reason it
     * settles every member directly rather than through resettle(): the
     * sweep's staleness predicate assumes winners only ever move up, and
     * here they can move down. Keep it unconditional.
     */
    public function repair(string $axis, string $hash): void
    {
        foreach ($this->memberIds($axis, $hash) as $id) {
            $this->settle($id, $this->hashes($id));
        }
    }

    /**
     * repair() for many clusters at once, settling each member ONCE however
     * many of the clusters it belongs to: what a tombstone's repoint needs,
     * where thousands of rows change clusters together and a member sits in
     * one cluster per axis.
     *
     * Members and their stamps are read in chunks, and each cluster's
     * eligibility is decided once for the pass. That memo is sound because
     * settling writes only winner stamps, and eligibility never reads them.
     *
     * @param  iterable<array{string, string}>  $clusters  [axis, hash] pairs
     */
    public function repairMany(iterable $clusters): void
    {
        $hashesByAxis = [];

        foreach ($clusters as [$axis, $hash]) {
            $hashesByAxis[$axis][$hash] = true;
        }

        $ids = [];
        $activities = $this->activitiesTable();
        $groupings = $this->groupingsTable();

        foreach ($hashesByAxis as $axis => $hashes) {
            foreach (array_chunk(array_keys($hashes), 500) as $chunk) {
                $members = $this->activityModel()->newQuery()
                    ->join($groupings, fn (JoinClause $join) => $join
                        ->on("{$groupings}.activity_id", '=', "{$activities}.id")
                        ->where("{$groupings}.bucket", $axis))
                    ->whereIn("{$groupings}.hash", $chunk)
                    ->pluck("{$activities}.id");

                foreach ($members as $id) {
                    $ids[$id] = true;
                }
            }
        }

        $this->eligibility = [];

        try {
            foreach (array_chunk(array_keys($ids), 500) as $chunk) {
                $rows = $this->groupings()
                    ->whereIn('activity_id', $chunk)
                    ->whereNotIn('bucket', $this->manager()->rowBackedBuckets())
                    ->orderBy('bucket')
                    ->toBase()
                    ->get([$this->groupingKey(), 'activity_id', 'bucket', 'hash', 'winner'])
                    ->groupBy('activity_id');

                foreach ($rows as $id => $own) {
                    $this->settle($id, $own->pluck('hash', 'bucket')->all(), $this->stamps($own));
                }
            }
        } finally {
            $this->eligibility = null;
        }
    }

    protected function manager(): StoryfeedManager
    {
        return app(StoryfeedManager::class);
    }

    /**
     * Re-decide members of the activity's clusters that are stamped for a
     * different axis than the cluster now warrants — the threshold-crossing
     * sweep. Once a cluster has settled this selects nothing.
     *
     * "Stale" on an eligible axis X means: no winner stamped on X or on any
     * axis that outranks X. That is decidable from the rows as they are,
     * because the winner column already has three states — null (never
     * decided), true (won), false (lost) — and priority is registration
     * order. What it deliberately does NOT mean is `winner = false` on X:
     * false is the correct, settled state of a member whose winner is a
     * higher-priority axis, and reading it as "undecided" re-settled every
     * member of every eligible loser on every publish, forever (W108, Solo
     * todo 887 — quadratic in cluster size, and nothing ever changed).
     *
     * The predicate assumes winners are monotone: within a day a cluster
     * only grows, so a member whose winner outranks X is correctly settled
     * and stays so. The paths where that is false — deletion, composite
     * claiming, releases — do not come through here; see afterDelete() and
     * repair().
     *
     * @param  array<string, string>  $hashes  bucket => hash
     */
    protected function resettle(array $hashes): void
    {
        $axes = $this->manager()->aggregateAxes();
        $activities = $this->activitiesTable();
        $groupings = $this->groupingsTable();

        foreach ($axes as $position => $axis) {
            // An ineligible cluster cannot have made anyone's stamp stale.
            if (! isset($hashes[$axis]) || ! $this->eligible($axis, $hashes[$axis])) {
                continue;
            }

            // This axis and every axis that beats it on priority.
            $outranking = array_slice($axes, 0, $position + 1);

            $stale = $this->clusterActivities($axis, $hashes[$axis])
                ->whereNotExists(fn ($query) => $query
                    ->from($groupings, 'settled')
                    ->whereColumn('settled.activity_id', "{$activities}.id")
                    ->whereIn('settled.bucket', $outranking)
                    ->where('settled.winner', true))
                ->pluck("{$activities}.id");

            foreach ($stale as $id) {
                $this->settle($id, $this->hashes($id));
            }
        }
    }

    /**
     * Distinct entities in the collapsing dimension. Counted through a
     * subquery because multi-column COUNT(DISTINCT …) is not portable.
     */
    protected function distinctRoles(string $axis, string $hash, string $role): int
    {
        $activities = $this->activitiesTable();

        $distinct = $this->clusterActivities($axis, $hash)
            ->whereNotNull("{$activities}.{$role}_type")
            ->select(["{$activities}.{$role}_type", "{$activities}.{$role}_id"])
            ->distinct()
            ->toBase();

        return $this->activityModel()->getConnection()->query()->fromSub($distinct, 'd')->count();
    }

    /**
     * @return Collection<int, int|string>
     */
    protected function memberIds(string $axis, string $hash)
    {
        return $this->clusterActivities($axis, $hash)->pluck($this->activitiesTable().'.id');
    }

    /**
     * Members of one cluster. Deliberately NOT gated on published_at: a
     * future-dated activity is a member of its cluster the moment it exists,
     * so the group it belongs to is decided once rather than re-decided when
     * the clock passes it.
     *
     * @return ActivityBuilder<Activity>
     */
    protected function clusterActivities(string $axis, string $hash)
    {
        $activities = $this->activitiesTable();
        $groupings = $this->groupingsTable();

        return $this->activityModel()->newQuery()
            ->join($groupings, fn (JoinClause $join) => $join
                ->on("{$groupings}.activity_id", '=', "{$activities}.id")
                ->where("{$groupings}.bucket", $axis))
            ->where("{$groupings}.hash", $hash);
    }

    /**
     * The activity's candidate hashes. Batch rows are NOT candidates:
     * batches are infrastructure with no feed effect yet (docs/grouping.md),
     * so curation must neither pick nor stamp them.
     *
     * @return array<string, string> bucket => hash
     */
    protected function hashes(int|string $activityId): array
    {
        return $this->groupings()
            ->where('activity_id', $activityId)
            ->whereNotIn('bucket', app(StoryfeedManager::class)->rowBackedBuckets())
            ->pluck('hash', 'bucket')
            ->all();
    }

    /** @return Builder<Grouping> */
    protected function groupings()
    {
        $model = config('storyfeed.models.grouping', Grouping::class);

        return $model::query();
    }

    protected function activityModel(): Activity
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return new $model;
    }

    protected function activitiesTable(): string
    {
        return $this->activityModel()->getTable();
    }

    protected function groupingKey(): string
    {
        $model = config('storyfeed.models.grouping', Grouping::class);

        return (new $model)->getKeyName();
    }

    protected function groupingsTable(): string
    {
        $model = config('storyfeed.models.grouping', Grouping::class);

        return (new $model)->getTable();
    }
}
