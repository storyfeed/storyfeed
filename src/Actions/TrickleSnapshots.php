<?php

namespace Storyfeed\Actions;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;

/**
 * The self-correcting scheduled worker: snapshots uncached activities
 * newest-first, and prunes orphans — an activity whose entity no longer
 * exists is soft-deleted, so the backlog converges to zero instead of
 * spinning forever on dead rows.
 *
 * Schedule: $schedule->command('storyfeed:trickle')->everyFifteenMinutes()
 *           ->withoutOverlapping();
 */
class TrickleSnapshots
{
    /**
     * @return array{snapshotted: int, pruned: int}
     */
    public function __invoke(?int $limit = null): array
    {
        $limit ??= (int) config('storyfeed.trickle.limit', 200);

        $snapshotted = 0;
        $pruned = 0;

        $activities = $this->uncached()
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get();

        foreach ($activities as $activity) {
            $orphaned = false;

            foreach (RebuildSnapshots::ROLES as $role) {
                if ($activity->{"{$role}_type"} === null || $activity->{"cached_{$role}_id"} !== null) {
                    continue;
                }

                $model = $this->resolve($activity->{"{$role}_type"}, $activity->{"{$role}_id"});

                if ($model === null) {
                    $orphaned = true;

                    continue;
                }

                $activity->{"cached_{$role}_id"} = (new SnapshotEntity)($model)->getKey();
            }

            if ($orphaned) {
                $activity->delete();
                $pruned++;

                continue;
            }

            $activity->save();

            (new WriteGroupings)($activity); // converge legacy rows into groups

            $snapshotted++;
        }

        return ['snapshotted' => $snapshotted, 'pruned' => $pruned];
    }

    protected function uncached(): ActivityBuilder
    {
        return $this->activityQuery()->where(function (ActivityBuilder $query) {
            foreach (RebuildSnapshots::ROLES as $role) {
                $query->orWhere(function (ActivityBuilder $q) use ($role) {
                    $q->whereNotNull("{$role}_type")->whereNull("cached_{$role}_id");
                });
            }
        });
    }

    /**
     * @return (Model&Feedable)|null
     */
    protected function resolve(string $type, int|string $id): ?Model
    {
        $class = Relation::getMorphedModel($type) ?? (class_exists($type) ? $type : null);

        if ($class === null || ! is_a($class, Model::class, true) || ! is_a($class, Feedable::class, true)) {
            return null;
        }

        /** @var (Model&Feedable)|null guarded by the is_a checks above */
        return $class::query()->find($id);
    }

    protected function activityQuery(): ActivityBuilder
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return $model::query();
    }
}
