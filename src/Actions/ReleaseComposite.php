<?php

namespace Storyfeed\Actions;

use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;

/**
 * A force-deleted composite parent releases its members back to inference —
 * the story is gone, but the events outlive it (the same doctrine as
 * parties: history outlives a retired integration).
 *
 * Soft-deleted parents release nothing: the cluster keeps rendering from
 * its members, and restoration puts the story back intact.
 */
class ReleaseComposite
{
    public function __invoke(Activity $activity): void
    {
        if (! $activity->isForceDeleting()) {
            return;
        }

        $grouping = config('storyfeed.models.grouping', Grouping::class);

        $isParent = $grouping::query()
            ->where('activity_id', $activity->getKey())
            ->where('bucket', 'composite')
            ->where('hash', $activity->uid)
            ->exists();

        if (! $isParent) {
            return;
        }

        $memberIds = $grouping::query()
            ->where('bucket', 'composite')
            ->where('hash', $activity->uid)
            ->where('activity_id', '!=', $activity->getKey())
            ->pluck('activity_id');

        // Claims released first, so WriteGroupings' claimed-guard passes.
        $grouping::query()
            ->where('bucket', 'composite')
            ->where('hash', $activity->uid)
            ->delete();

        $model = config('storyfeed.models.activity', Activity::class);

        $write = new WriteGroupings;
        $curate = new CurateCluster;

        foreach ($model::query()->whereKey($memberIds)->get() as $member) {
            $write($member);
            $curate($member);
        }
    }
}
