<?php

namespace Storyfeed\Actions;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
     */
    public function afterDelete(Activity $activity): void
    {
        $hashes = $this->hashes($activity->getKey());

        // A force-deleted activity can never come back, so its candidate
        // hashes are orphans — the same cleanup PruneActivities does.
        if ($activity->isForceDeleting()) {
            $this->groupings()->where('activity_id', $activity->getKey())->delete();
        }

        foreach ($hashes as $axis => $hash) {
            foreach ($this->memberIds($axis, $hash) as $id) {
                if ($id !== $activity->getKey()) {
                    $this->settle($id, $this->hashes($id));
                }
            }
        }

        // A soft-deleted activity keeps its rows, and may be restored, so it
        // is re-decided like any other member.
        if (! $activity->isForceDeleting()) {
            $this->settle($activity->getKey(), $hashes);
        }
    }

    /**
     * Decide and stamp one activity from its own candidate hashes.
     *
     * @param  array<string, string>  $hashes  bucket => hash
     */
    protected function settle(int|string $activityId, array $hashes): void
    {
        if ($hashes === []) {
            return;
        }

        $winner = $this->decide($hashes);

        DB::transaction(function () use ($activityId, $winner) {
            // Cleared first, so there is never a moment with two winners.
            // Batch rows stay winner = null — they are outside curation.
            $this->groupings()
                ->where('activity_id', $activityId)
                ->where('bucket', '!=', $winner)
                ->whereNotIn('bucket', StoryfeedManager::ROW_BACKED_BUCKETS)
                ->update(['winner' => false]);

            $this->groupings()
                ->where('activity_id', $activityId)
                ->where('bucket', $winner)
                ->update(['winner' => true]);
        });
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

    protected function manager(): StoryfeedManager
    {
        return app(StoryfeedManager::class);
    }

    /**
     * Re-decide members of the activity's clusters that are stamped for a
     * different axis than the cluster now warrants — the threshold-crossing
     * sweep. Once a cluster has settled this selects nothing.
     *
     * @param  array<string, string>  $hashes  bucket => hash
     */
    protected function resettle(array $hashes): void
    {
        foreach ($this->manager()->aggregateAxes() as $axis) {
            // An ineligible cluster cannot have made anyone's stamp stale.
            if (! isset($hashes[$axis]) || ! $this->eligible($axis, $hashes[$axis])) {
                continue;
            }

            $stale = $this->clusterActivities($axis, $hashes[$axis])
                ->where(fn ($query) => $query
                    ->whereNull($this->groupingsTable().'.winner')
                    ->orWhere($this->groupingsTable().'.winner', false))
                ->pluck($this->activitiesTable().'.id');

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
            ->whereNotIn('bucket', StoryfeedManager::ROW_BACKED_BUCKETS)
            ->pluck('hash', 'bucket')
            ->all();
    }

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

    protected function groupingsTable(): string
    {
        $model = config('storyfeed.models.grouping', Grouping::class);

        return (new $model)->getTable();
    }
}
