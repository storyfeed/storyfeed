<?php

namespace Storyfeed\Actions;

use Illuminate\Support\Carbon;
use Storyfeed\Models\Batch;

/**
 * The timeliness sweep: close open batches whose `closes_at` has passed and
 * fire BatchClosed for each non-empty one. Given a number of minutes, it
 * closes by that quiet window instead (last seen plus the minutes), as it
 * did before batches had a `closes_at`.
 *
 * Not required for correctness — AssignToBatch closes a stale batch lazily
 * on the actor's next publish. This sweep is for actors who walked away,
 * so digest listeners hear about their batch promptly rather than at the
 * actor's next visit. Schedule storyfeed:close-batches if that matters to
 * the app; skip it otherwise.
 */
class CloseBatches
{
    /**
     * @return int number of batches closed
     */
    public function __invoke(?int $quietMinutes = null): int
    {
        // Membership uses event time. The sweep asks whether that event-time
        // window has elapsed as of now; closed_at records the actual close.
        // A drained historical window is therefore eligible immediately.
        $now = Carbon::now();
        $cutoff = $now->copy()->subMinutes($quietMinutes ?? (int) config('storyfeed.grouping.batch.quiet_minutes', 10));

        $model = config('storyfeed.models.batch', Batch::class);

        $closed = 0;

        $lastSeen = fn ($query) => $query
            ->where('last_activity_at', '<=', $cutoff)
            ->orWhere(fn ($empty) => $empty
                ->whereNull('last_activity_at')
                ->where('opened_at', '<=', $cutoff));

        $model::query()
            ->open()
            ->when(
                $quietMinutes !== null,
                $lastSeen,
                // A batch with no closes_at (opened before it existed, and
                // missed by the backfill) ends where it always did.
                fn ($query) => $query->where(fn ($query) => $query
                    ->where('closes_at', '<=', $now)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('closes_at')->where($lastSeen))),
            )
            ->orderBy('id')
            ->chunkById(200, function ($batches) use ($now, &$closed) {
                foreach ($batches as $batch) {
                    if ((new CloseBatch)($batch, $now)) {
                        $closed++;
                    }
                }
            });

        return $closed;
    }
}
