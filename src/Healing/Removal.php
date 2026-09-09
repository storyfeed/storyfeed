<?php

namespace Storyfeed\Healing;

use Carbon\CarbonImmutable;

/**
 * Durable evidence that the last live story on a key was removed on purpose.
 *
 * Read it through Removals::removed(); it is never returned while a live
 * story exists on the key, so holding one means the key is empty because
 * something removed it, not because nothing was ever recorded there.
 */
final readonly class Removal
{
    public function __construct(
        public string $verb,
        public string $objectType,
        public int|string $objectId,
        /** When the last live story left the key. Refreshed by every later removal on the same key. */
        public CarbonImmutable $removedAt,
        /** The removed story's own published_at, so a retention window can be compared against it. */
        public ?CarbonImmutable $publishedAt,
    ) {}
}
