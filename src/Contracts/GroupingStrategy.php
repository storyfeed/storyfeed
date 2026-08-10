<?php

namespace Storyfeed\Contracts;

use Storyfeed\Models\Activity;

/**
 * Computes the candidate grouping hashes for an activity, one per axis.
 * Hashes are written to the groupings table at publish time; the curation
 * process selects among competing axes at read time (docs/grouping.md).
 */
interface GroupingStrategy
{
    /**
     * @return array<string, string> axis (bucket) => deterministic hash
     */
    public function hashes(Activity $activity): array;
}
