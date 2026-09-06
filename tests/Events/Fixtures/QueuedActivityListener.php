<?php

namespace Storyfeed\Tests\Events\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Storyfeed\Events\ActivityPublished;

/**
 * A queued listener, as a consumer would write one for broadcasting or
 * notifications. Named (not anonymous) because CallQueuedListener resolves
 * the class by name on the worker. Records what the worker's copy looked
 * like, so a test can compare it with the row.
 */
class QueuedActivityListener implements ShouldQueue
{
    /** @var array<int, array<string, mixed>> */
    public static array $seen = [];

    public function handle(ActivityPublished $event): void
    {
        $activity = $event->activity;

        static::$seen[] = [
            'uid' => $activity->uid,
            'exists' => $activity->exists,
            'relations' => array_keys($activity->getRelations()),
            // Reading a relation on the detached copy lazy-loads it from
            // the live database — the worker can still reach the object.
            'object_tracking_number' => $activity->object?->tracking_number,
        ];
    }
}
