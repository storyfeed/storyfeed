<?php

namespace Storyfeed\Actions;

use Storyfeed\Models\Grouping;

/**
 * Remove everything that points at a set of activity ids — grouping rows
 * and participant rows — ahead of a bulk hard delete of the activities
 * themselves.
 *
 * There is no DB-level cascade, by design, and a bulk `forceDelete()` fires
 * no model events, so a caller that hard-deletes by query has to do this
 * bookkeeping itself. Three paths did, each inline: `PruneActivities`,
 * `PendingActivity::supersede()` in force mode, and — as of 2026-09-05 —
 * `InteractsWithFeed::forceDeleteFromFeed()`, which had not, and left
 * grouping and participant rows pointing at primary keys that no longer
 * existed. The last one is the reason this is a named action: it was the
 * one hard-delete path with no opt-in in front of it, firing whenever a
 * `Feedable` model was force-deleted, and it had reinvented half of the
 * dance.
 *
 * Participant rows go through `SyncParticipants::forget()` because that is
 * already the one place that knows the participants table; grouping rows
 * are deleted here because no action owned that yet.
 *
 * Snapshots are untouched on purpose: they are per-entity, not per-activity,
 * and orphan cleanup there is the trickle's job.
 */
class ForgetActivities
{
    public function __invoke(int|string ...$activityIds): void
    {
        if ($activityIds === []) {
            return;
        }

        $grouping = config('storyfeed.models.grouping', Grouping::class);

        $grouping::query()->whereIn('activity_id', $activityIds)->delete();

        SyncParticipants::forget(...$activityIds);
    }
}
