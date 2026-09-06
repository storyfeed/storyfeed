<?php

namespace Storyfeed\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Storyfeed\Events\Concerns\SerializesWithoutRelations;
use Storyfeed\Models\Activity;

/**
 * Dispatched when an activity is deleted (soft or forced), once the delete
 * has committed — inside a transaction that means the outermost commit, and
 * a rollback fires nothing.
 *
 * The activity is the in-memory model as it was: `exists` is false after a
 * force delete, `trashed()` is true after a soft one. There is nothing to
 * re-fetch, which is why this is not SerializesModels — a queued listener
 * gets the same detached copy, minus loaded relations.
 */
class ActivityDeleted implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesWithoutRelations;

    public function __construct(
        public Activity $activity,
    ) {}
}
