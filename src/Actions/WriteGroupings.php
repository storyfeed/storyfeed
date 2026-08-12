<?php

namespace Storyfeed\Actions;

use Storyfeed\Grouping\MultiAxisStrategy;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\StoryfeedManager;

/**
 * Write one candidate grouping hash per axis for an activity. Called at
 * publish time, and by the trickle for legacy/imported rows so ungrouped
 * activities converge into the grouped read path.
 */
class WriteGroupings
{
    public function __invoke(Activity $activity): void
    {
        $strategy = app(config('storyfeed.grouping.strategy', MultiAxisStrategy::class));

        $grouping = config('storyfeed.models.grouping', Grouping::class);

        $hashes = $strategy->hashes($activity);

        foreach ($hashes as $axis => $hash) {
            $grouping::query()->updateOrCreate(
                ['activity_id' => $activity->getKey(), 'bucket' => $axis],
                ['hash' => $hash],
            );
        }

        // An activity edited to drop a role stops emitting that axis; without
        // this its old bucket would linger and keep grouping it forever.
        // The batch bucket is exempt: batch membership is written by the
        // publish path, not the strategy, so it is never in $hashes — the
        // delete would otherwise destroy it on every re-run (trickle!).
        $grouping::query()
            ->where('activity_id', $activity->getKey())
            ->whereNotIn('bucket', StoryfeedManager::ROW_BACKED_BUCKETS)
            ->when($hashes !== [], fn ($query) => $query->whereNotIn('bucket', array_keys($hashes)))
            ->delete();
    }
}
