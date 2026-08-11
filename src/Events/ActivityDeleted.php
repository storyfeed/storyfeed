<?php

namespace Storyfeed\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Storyfeed\Models\Activity;

/**
 * Dispatched when an activity is deleted (soft or forced).
 */
class ActivityDeleted
{
    use Dispatchable;

    public function __construct(
        public Activity $activity,
    ) {}
}
