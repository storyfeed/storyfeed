<?php

namespace Storyfeed\Actions;

use Illuminate\Database\Eloquent\Model;

/**
 * Permanently delete every activity involving a model, including activities
 * that were already soft-deleted — and everything that points at them. What
 * a Feedable's `forceDeleted` event does.
 *
 * A bulk `forceDelete()` fires no model events, so nothing downstream
 * hears about the rows going. Until 2026-09-05 this was that one query, and
 * it left `feed_groupings` and `feed_participants` rows behind pointing at
 * primary keys that no longer existed. It was the one hard-delete path with
 * no opt-in in front of it: `replace()` defaults to soft, the trickle prunes
 * only when asked, but this fires for every Feedable that is force-deleted.
 * So the ids are collected first and ForgetActivities clears their rows
 * before the delete, the same way PruneActivities does it.
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
        $forget = new ForgetActivities;

        while (true) {
            $ids = DeleteFromFeed::query()
                ->withTrashed()
                ->involving($model)
                ->limit(500)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            DeleteFromFeed::query()->getConnection()->transaction(function () use ($forget, $ids) {
                $forget(...$ids);

                DeleteFromFeed::query()->withTrashed()->whereKey($ids)->forceDelete();
            });
        }
    }
}
