<?php

namespace Storyfeed\Actions;

use Illuminate\Support\Carbon;
use Storyfeed\Events\BatchClosed;
use Storyfeed\Events\Snapshots\BatchSnapshot;
use Storyfeed\Models\Batch;

class CloseBatch
{
    /** Return whether this caller transitioned the batch from open to closed. */
    public function __invoke(Batch $batch, Carbon $now): bool
    {
        // A scheduler lock only coordinates sweepers, not publish-time closes.
        // Let the database arbitrate the transition at both call sites: stale
        // readers affect zero rows and must not announce or bundle it again.
        if ($batch->newQuery()->whereKey($batch->getKey())->open()->update(['closed_at' => $now]) === 0) {
            return false;
        }

        $batch->refresh();

        if ($batch->activities_count > 0) {
            // Publish-time callers already own a transaction; the event waits
            // for its commit before a digest listener sees the durable close.
            BatchClosed::dispatch(BatchSnapshot::fromModel($batch));

            if (config('storyfeed.grouping.composite.auto', true)) {
                (new BundleComposites)($batch);
            }
        }

        return true;
    }
}
