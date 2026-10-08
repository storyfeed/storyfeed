<?php

namespace Storyfeed\Actions;

use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\SyncToken;

/** Explicit history migration; pause publishers while this transaction runs. */
final class RebuildGroupingBursts
{
    /** @return array{processed: int, restamped: int, rehashed: int} */
    public function __invoke(): array
    {
        $activityClass = config('storyfeed.models.activity', Activity::class);
        $groupingClass = config('storyfeed.models.grouping', Grouping::class);
        $connection = (new $activityClass)->getConnection();

        return $connection->transaction(function () use ($activityClass, $groupingClass, $connection): array {
            $axes = array_keys(array_filter(app(StoryfeedManager::class)->registeredAxes(), fn ($axis) => $axis->usesBursts()));
            $groupingClass::query()->whereIn('bucket', [...$axes, 'summary.hour', 'summary.day', 'summary.week', 'summary.month'])->delete();
            $connection->table(config('storyfeed.tables.grouping_bursts', 'feed_grouping_bursts'))->delete();
            $write = new WriteGroupings;
            $restamped = 0;
            $curate = new CurateCluster(function (bool $changed) use (&$restamped): void {
                $restamped += (int) $changed;
            });
            $count = 0;
            $rehashed = 0;
            // Event chronology, with a total stable tie-break. Includes soft
            // deleted history so restore never changes the burst boundaries.
            foreach ($activityClass::withTrashed()->orderBy('published_at')->orderBy('id')->lazy(500) as $activity) {
                $write($activity);
                $rehashed += (int) $groupingClass::query()->where('activity_id', $activity->getKey())->whereIn('bucket', $axes)->exists();
                $curate($activity);
                $count++;
            }
            if ($count > 0) {
                SyncToken::bump();
            }

            // Rehashed counts rebuilt memberships, not identical hashes
            // compared with the explicitly discarded history.
            return ['processed' => $count, 'restamped' => $restamped, 'rehashed' => $rehashed];
        });
    }
}
