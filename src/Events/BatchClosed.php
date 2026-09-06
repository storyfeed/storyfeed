<?php

namespace Storyfeed\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Storyfeed\Events\Concerns\SerializesWithoutRelations;
use Storyfeed\Models\Batch;

/**
 * A batch of activity has ended (its quiet window elapsed). The digest
 * hook: listen to this to send one notification about a burst of activity
 * instead of one per member. Members via $batch->activities().
 *
 * Fired lazily when the actor's next publish closes a stale batch, and by
 * storyfeed:close-batches for actors who walked away — schedule that
 * command when prompt delivery matters.
 *
 * The lazy close happens INSIDE the publish transaction, so this event
 * waits for that transaction's outermost commit: a digest listener never
 * sees a batch whose close is still uncommitted, and a rolled-back publish
 * closes nothing and sends nothing.
 */
class BatchClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;
    use SerializesWithoutRelations;

    public function __construct(public Batch $batch) {}
}
