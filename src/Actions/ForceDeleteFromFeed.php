<?php

namespace Storyfeed\Actions;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;

/**
 * Permanently delete every activity involving a model, including activities
 * that were already soft-deleted — and everything that points at them.
 * Erasure, when asked with `forceDeleteFromFeed()`. Until 2026-09-23 it was
 * what a Feedable's `forceDeleted` event did; a force delete now makes the
 * model's tombstone permanent (TombstoneEntity) and its activities stay.
 *
 * A bulk `forceDelete()` fires no model events, so nothing downstream
 * hears about the rows going. Until 2026-09-05 this was that one query, and
 * it left `feed_groupings` and `feed_participants` rows behind pointing at
 * primary keys that no longer existed. It was the one hard-delete path with
 * no opt-in in front of it then: `replace()` defaults to soft, the trickle
 * prunes only when asked, but this fired for every Feedable that was
 * force-deleted. So the ids are collected first and ForgetActivities clears
 * their rows before the delete, the same way PruneActivities does it.
 *
 * Still a bulk operation, deliberately. Per-model deletes would get the
 * events back at the cost of a query per activity on exactly the path that
 * exists to be fast, and curation has nothing to re-decide for a cluster
 * whose members are all leaving at once.
 *
 * Chunked because `involving()` is an index over the participants table:
 * each pass forgets the rows it deletes, so the next pass sees only what is
 * left and the loop converges without a running exclusion list.
 *
 * EACH CHUNK IS ONE TRANSACTION, and that is the half of this that matters:
 * the forget clears the grouping and participant rows and the `forceDelete`
 * removes the activities, and a failure between them would leave one
 * without the other.
 */
class ForceDeleteFromFeed
{
    public function __invoke(Model $model): void
    {
        $this->activities(fn () => DeleteFromFeed::query()->withTrashed()->involving($model));
    }

    /**
     * Permanently delete the activities a query selects, the same way. The
     * closure returns a fresh query each pass. A verb's
     * `->forgetWhenMissing()` comes through here with a narrower one.
     *
     * @param  Closure(): ActivityBuilder<Activity>  $query
     * @return int how many activities were deleted
     */
    public function activities(Closure $query): int
    {
        $forget = new ForgetActivities;
        $deleted = 0;

        while (true) {
            $ids = $query()->limit(500)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            DeleteFromFeed::query()->getConnection()->transaction(function () use ($forget, $ids) {
                $forget(...$ids);

                DeleteFromFeed::query()->withTrashed()->whereKey($ids)->forceDelete();
            });

            $deleted += count($ids);
        }

        return $deleted;
    }
}
