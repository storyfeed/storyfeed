<?php

namespace Storyfeed\Healing;

use Closure;
use Storyfeed\Models\Activity;

/**
 * An explicit request to retire one existing story for permanent source absence.
 *
 * Only for sources that cannot return with the same identity. Restorable sources
 * and detached-but-present sources are outside this contract. No resurrection
 * state is stored, and no missing activity ever causes a write.
 */
final readonly class StoryRetirement
{
    /**
     * @param  Closure(Activity): bool  $whenAbsent  Read-only predicate over the freshly loaded activity. Check both policy eligibility and permanent source absence; called again under the activity lock at write time.
     * @param  array<string, mixed>  $meta  App-owned explanation shown in the preview.
     */
    public function __construct(
        public string $label,
        public int|string $activityId,
        public Closure $whenAbsent,
        public array $meta = [],
    ) {}
}
