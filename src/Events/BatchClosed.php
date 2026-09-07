<?php

namespace Storyfeed\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Storyfeed\Events\Snapshots\BatchSnapshot;

/**
 * Event-time snapshot delivered after the outermost commit; rollback fires nothing.
 * Sync and queued listeners receive the same immutable facts, with no re-resolution.
 * BREAKING: the payload is a BatchSnapshot, never an Eloquent model.
 */
class BatchClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly BatchSnapshot $batch) {}
}
