<?php

namespace Storyfeed\Contracts;

use Storyfeed\Models\Activity;

/**
 * Computes the candidate grouping hashes for an activity, one per axis.
 * Hashes are written to the groupings table at publish time, where curation
 * also stamps the winning axis among them; reads use the stamp (docs/grouping.md).
 */
interface GroupingStrategy
{
    /**
     * @return array<string, string> axis (bucket) => deterministic hash
     */
    public function hashes(Activity $activity): array;
}
