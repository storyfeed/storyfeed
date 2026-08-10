<?php

namespace Storyfeed\Grouping;

use Storyfeed\Contracts\GroupingStrategy;
use Storyfeed\Models\Activity;

/**
 * No grouping: every activity stands alone in the feed.
 */
class NullStrategy implements GroupingStrategy
{
    public function hashes(Activity $activity): array
    {
        return [];
    }
}
