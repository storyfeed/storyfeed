<?php

namespace Storyfeed\Actions;

use Storyfeed\Grouping\MultiAxisStrategy;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;

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

        foreach ($strategy->hashes($activity) as $axis => $hash) {
            $grouping::query()->updateOrCreate(
                ['activity_id' => $activity->getKey(), 'bucket' => $axis],
                ['hash' => $hash],
            );
        }
    }
}
