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
 * Writes no evidence of what it removed, on purpose and now without
 * exception. A row past the retention window left because of a policy the
 * app configured, not because anyone decided about that row — so there is
 * nothing about it worth recording that the policy does not already say.
 *
 * It used to advance a retention watermark in `feed_meta` and leave a
 * per-key evidence table unswept. Both are gone: they existed so that
 * something could later tell a deliberate removal from an expiry, and
 * nothing in this package ever asked. An app that needs to know keeps its
 * own record, where it also knows why.
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

        return ['pruned' => $pruned, 'enabled' => true];
    }
}
