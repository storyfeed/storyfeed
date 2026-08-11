<?php

namespace Storyfeed\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Storyfeed\Models\Batch;

/**
 * A batch of activity has ended (its quiet window elapsed). The digest
 * hook: listen to this to send one notification about a burst of activity
 * instead of one per member. Members via $batch->activities().
 *
 * Fired lazily when the actor's next publish closes a stale batch, and by
 * storyfeed:close-batches for actors who walked away — schedule that
 * command when prompt delivery matters.
 */
class BatchClosed
{
    use Dispatchable;

    public function __construct(public Batch $batch) {}
}
