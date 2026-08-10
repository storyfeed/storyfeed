<?php

namespace Storyfeed\Grouping;

use Storyfeed\Contracts\GroupingStrategy;
use Storyfeed\Models\Activity;

/**
 * The default candidate axes, day-bucketed:
 *
 *  - repeat:  the same actor doing the same kind of thing
 *             ("Sally uploaded 12 photos")
 *  - actors:  many actors, same verb + target
 *             ("Bob, Sally, and 3 others uploaded files to Project X")
 *  - targets: same actor + verb across many targets
 *             ("Sally commented on 2 projects")
 */
class MultiAxisStrategy implements GroupingStrategy
{
    public function hashes(Activity $activity): array
    {
        $day = ($activity->published_at ?? now())->toDateString();

        $hashes = [
            'repeat' => $this->hash([
                $activity->actor_type, $activity->actor_id,
                $activity->verb,
                $activity->object_type,
                $activity->target_type, $activity->target_id,
                $day,
            ]),
        ];

        if ($activity->target_type !== null) {
            $hashes['actors'] = $this->hash([
                $activity->verb,
                $activity->target_type, $activity->target_id,
                $day,
            ]);
        }

        if ($activity->actor_type !== null) {
            $hashes['targets'] = $this->hash([
                $activity->actor_type, $activity->actor_id,
                $activity->verb,
                $day,
            ]);
        }

        return $hashes;
    }

    /**
     * @param  array<int, string|int|null>  $parts
     */
    protected function hash(array $parts): string
    {
        return implode(':', array_map(fn ($part) => $part ?? '', $parts));
    }
}
