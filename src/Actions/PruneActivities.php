<?php

namespace Storyfeed\Actions;

use Storyfeed\Models\Activity;
use Storyfeed\Support\Chronology;

/**
 * Retire activities older than the configured retention window. Strictly
 * opt-in: with no age configured (and none given), nothing is deleted.
 *
 * Force-deletes in chunks — including soft-deleted rows accumulated by
 * replace semantics and cascade deletes — and removes their grouping and
 * participant rows first, through `ForgetActivities` (no DB-level cascade
 * exists, by design). Snapshots are untouched: they are per-entity.
 * The trickle does not delete orphaned snapshots either.
 *
 * Writes no removal evidence per row, on purpose. A live row past the
 * window is retention, not a deliberate removal, and a soft-deleted row's
 * removal — if it was one — was recorded when it left. What a sweep does
 * write is the retention watermark (`Healing\Removals::prunedBefore()`), one
 * feed_meta row, after the sweep completes. The `feed_removals` table is
 * never swept: it is the evidence this command would otherwise erase.
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
        $forget = new ForgetActivities;

        $pruned = 0;

        while (true) {
            $ids = $activity::query()
                ->withTrashed()
                ->where('published_at', '<', Chronology::stamp($cutoff))
                ->limit(500)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $forget(...$ids);

            $activity::query()->withTrashed()->whereKey($ids)->forceDelete();

            $pruned += $ids->count();
        }

        (new RecordRemovals)->prunedBefore($cutoff);

        return ['pruned' => $pruned, 'enabled' => true];
    }
}
