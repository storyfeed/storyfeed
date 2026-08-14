<?php

namespace Storyfeed\Actions;

use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;

/**
 * Retire activities older than the configured retention window. Strictly
 * opt-in: with no age configured (and none given), nothing is deleted.
 *
 * Force-deletes in chunks — including soft-deleted rows accumulated by
 * replace semantics and cascade deletes — and removes their grouping rows
 * (no DB-level cascade exists, by design). Snapshots are untouched: they are
 * per-entity, and orphan cleanup is the trickle's job.
 */
class PruneActivities
{
    /**
     * @return array{pruned: int, enabled: bool}
     */
    public function __invoke(?int $days = null): array
    {
        $days ??= config('storyfeed.prune.after_days');

        if ($days === null) {
            return ['pruned' => 0, 'enabled' => false];
        }

        $cutoff = now()->subDays((int) $days);

        $activity = config('storyfeed.models.activity', Activity::class);
        $grouping = config('storyfeed.models.grouping', Grouping::class);

        $pruned = 0;

        while (true) {
            $ids = $activity::query()
                ->withTrashed()
                ->where('published_at', '<', $cutoff)
                ->limit(500)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $grouping::query()->whereIn('activity_id', $ids)->delete();

            SyncParticipants::forget(...$ids);

            $activity::query()->withTrashed()->whereKey($ids)->forceDelete();

            $pruned += $ids->count();
        }

        return ['pruned' => $pruned, 'enabled' => true];
    }
}
