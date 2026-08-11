<?php

namespace Storyfeed;

use Storyfeed\Models\Activity;

/**
 * One item selected by phase 1 of the read: either a grouped hash (with its
 * true member count) or a solo activity. Candidates from both streams are
 * merged into one totally-ordered page — see FeedBuilder::selectItems().
 */
final class FeedCandidate
{
    private function __construct(
        public readonly string $latest,
        public readonly ?string $axis,
        public readonly ?string $hash,
        public readonly int $count,
        public readonly ?Activity $activity,
    ) {}

    public static function group(string $latest, string $axis, string $hash, int $count): self
    {
        return new self($latest, $axis, $hash, $count, null);
    }

    public static function solo(string $latest, Activity $activity): self
    {
        return new self($latest, null, null, 1, $activity);
    }

    public function isGroup(): bool
    {
        return $this->hash !== null;
    }
}
