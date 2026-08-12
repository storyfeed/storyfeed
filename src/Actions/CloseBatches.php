<?php

namespace Storyfeed\Actions;

use Illuminate\Support\Carbon;
use Storyfeed\Events\BatchClosed;
use Storyfeed\Models\Batch;

/**
 * The timeliness sweep: close open batches whose quiet window has elapsed
 * and fire BatchClosed for each non-empty one.
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
        $quiet = $quietMinutes ?? (int) config('storyfeed.grouping.batch.quiet_minutes', 10);

        $now = Carbon::now();
        $cutoff = $now->copy()->subMinutes($quiet);

        $model = config('storyfeed.models.batch', Batch::class);

        $closed = 0;

        $model::query()
            ->open()
            ->where(fn ($query) => $query
                ->where('last_activity_at', '<=', $cutoff)
                ->orWhere(fn ($empty) => $empty
                    ->whereNull('last_activity_at')
                    ->where('opened_at', '<=', $cutoff)))
            ->orderBy('id')
            ->chunkById(200, function ($batches) use ($now, &$closed) {
                foreach ($batches as $batch) {
                    $batch->forceFill(['closed_at' => $now])->save();

                    if ($batch->activities_count > 0) {
                        BatchClosed::dispatch($batch);

                        if (config('storyfeed.grouping.composite.auto', true)) {
                            (new BundleComposites)($batch);
                        }
                    }

                    $closed++;
                }
            });

        return $closed;
    }
}
