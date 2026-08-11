<?php

namespace Storyfeed\Payload;

use Illuminate\Support\Collection;
use Storyfeed\Models\Activity;

/**
 * One selected feed item on its way to the presenter: either a curated group
 * (axis + hash + the TRUE member total) or a solo activity.
 *
 * `count` is the aggregate from the group-selection query — not
 * `$members->count()`, which is capped at `grouping.children_limit`.
 *
 * @phpstan-type SliceMembers Collection<int, Activity>
 */
final class GroupSlice
{
    /**
     * @param  Collection<int, Activity>  $members  newest first, capped
     * @param  int|null  $actorCount  distinct actors across ALL members —
     *                                null when it was not computed
     */
    public function __construct(
        public readonly ?string $axis,
        public readonly ?string $hash,
        public readonly int $count,
        public readonly Collection $members,
        public readonly ?int $actorCount = null,
    ) {}

    /**
     * @param  Collection<int, Activity>  $members
     */
    public static function group(string $axis, string $hash, int $count, Collection $members, ?int $actorCount = null): self
    {
        return new self($axis, $hash, $count, $members, $actorCount);
    }

    public static function solo(Activity $activity): self
    {
        return new self(null, null, 1, Collection::make([$activity]));
    }

    /**
     * A group whose membership collapsed to one activity renders as a plain
     * activity node — grouping is a presentation win, not a wrapper tax.
     */
    public function isGroup(): bool
    {
        return $this->axis !== null && $this->count > 1;
    }
}
