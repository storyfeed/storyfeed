<?php

namespace Storyfeed\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Storyfeed\Events\Concerns\SerializesWithoutRelations;
use Storyfeed\Models\Activity;

/**
 * Dispatched after an activity is published and its transaction committed —
 * the hook for broadcasting, notifications, and live-feed invalidation.
 *
 * "Committed" means the OUTERMOST commit. publish() runs in its own
 * transaction, but inside a consumer's `DB::transaction()` that is a
 * savepoint, so the event waits for the consumer's commit and a rollback
 * fires nothing. With no transaction open, dispatch is immediate.
 *
 * The activity arrives with the relations the builder associated (actor,
 * object, target) already loaded — a synchronous listener pays no query
 * for them. A queued listener gets a copy without them; see
 * SerializesWithoutRelations for why, and why not SerializesModels.
 */
class ActivityPublished implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesWithoutRelations;

    public function __construct(
        public Activity $activity,
    ) {}
}
