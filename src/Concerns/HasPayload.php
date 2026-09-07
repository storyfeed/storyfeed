<?php

namespace Storyfeed\Concerns;

/**
 * Author the node shape in toPayload(); storage defaults to that shape.
 * Override toArray() only to enrich it with storage-only extras, as
 * FeedThread does for its version. Never derive the payload from storage.
 *
 * If toPayload() fell back to toArray(), forgetting to implement it would
 * leak storage internals into the node. The payload contract freezes at
 * v0.3: a leaked reserved key could never be withdrawn. In this direction,
 * a forgotten storage override merely fails to persist an extra, visible
 * on the next read and outside the contract. Fail-visible beats a silent,
 * permanent contract leak.
 */
trait HasPayload
{
    /** @return array<string, mixed> */
    abstract public function toPayload(): array;

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->toPayload();
    }
}
