<?php

namespace Storyfeed\Tests\Events\Fixtures;

use Illuminate\Contracts\Queue\ShouldQueue;
use Storyfeed\Events\ActivityDeleted;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Events\BatchClosed;

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

    public function handle(ActivityPublished|ActivityDeleted|BatchClosed $event): void
    {
        static::$seen[] = $event instanceof BatchClosed
            ? $event->batch->toPayload()
            : $event->activity->toPayload();
    }
}
