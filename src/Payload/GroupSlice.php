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
     * @param  array<string, int>  $distinct  TRUE distinct counts per role
     *                                        across ALL members (not just
     *                                        the capped list)
     * @param  array<string, int>  $tombstoned  TRUE counts per role of the
     *                                          distinct entities that are
     *                                          tombstones, from the same query
     */
    public function __construct(
        public readonly ?string $axis,
        public readonly ?string $hash,
        public readonly int $count,
        public readonly Collection $members,
        public readonly array $distinct = [],
        public readonly array $tombstoned = [],
    ) {}

    /**
     * @param  Collection<int, Activity>  $members
     * @param  array<string, int>  $distinct
     * @param  array<string, int>  $tombstoned
     */
    public static function group(string $axis, string $hash, int $count, Collection $members, array $distinct = [], array $tombstoned = []): self
    {
        return new self($axis, $hash, $count, $members, $distinct, $tombstoned);
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
