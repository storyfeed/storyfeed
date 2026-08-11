<?php

namespace Storyfeed\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Storyfeed\Models\Activity;

/**
 * Dispatched after an activity is published and its transaction committed —
 * the hook for broadcasting, notifications, and live-feed invalidation.
 */
class ActivityPublished
{
    use Dispatchable;

    public function __construct(
        public Activity $activity,
    ) {}
}
