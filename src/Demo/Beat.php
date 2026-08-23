<?php

namespace Storyfeed\Demo;

use Carbon\CarbonInterface;

/**
 * One activity a screenplay intends to publish, before anything touches the
 * database.
 *
 * Separating the intent from the write is what makes a demo testable without a
 * feed: a screenplay can be asserted for shape — "this day contains a burst of
 * eight uploads by one member, which is what makes the repeat group appear" —
 * in a plain unit test, and a broken demo fails in CI rather than on stage.
 */
readonly class Beat
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public string $verb,
        public CarbonInterface $publishedAt,
        public ?string $actor = null,
        public ?string $object = null,
        public ?string $target = null,
        public ?string $context = null,
        public array $data = [],
    ) {}
}
