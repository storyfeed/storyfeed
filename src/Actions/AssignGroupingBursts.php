<?php

namespace Storyfeed\Actions;

use Illuminate\Support\Carbon;
use Storyfeed\Models\Activity;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\Chronology;

/** Persisted, event-time windows. Call inside the grouping write transaction. */
final class AssignGroupingBursts
{
    /**
     * @param  array<string, string>  $hashes  logical strategy keys
     * @param  array<string, string>  $existing  the activity's stored memberships
     * @return array<string, string>
     */
    public function __invoke(Activity $activity, array $hashes, array $existing = []): array
    {
        $manager = app(StoryfeedManager::class);
        $connection = $activity->getConnection();
        $table = config('storyfeed.tables.grouping_bursts', 'feed_grouping_bursts');
        $pending = [];

        foreach ($hashes as $axis => $logical) {
            if (! $manager->axis($axis)?->usesBursts()) {
                continue;
            }
            $key = hash('sha256', $axis."\x1f".$logical);
            $prefix = 'b1:'.$key.':';
            // A refresh (including repoints with an unchanged logical key)
            // preserves the assignment, even after the window/config closes.
            if (isset($existing[$axis]) && str_starts_with($existing[$axis], $prefix)) {
                $hashes[$axis] = $existing[$axis];
            } elseif (isset($existing[$axis]) && preg_match('/^b1:[a-f0-9]{64}:(.+)$/', $existing[$axis], $match)) {
                // Role repoints change the logical identity, not its original
                // burst boundary. Keep the anchor when tombstoning/restoring
                // thousands of rows instead of replaying or merging history.
                $hashes[$axis] = $prefix.$match[1];
            } else {
                $pending[$key] = [$axis, $prefix];
            }
        }

        if ($pending === []) {
            return $hashes;
        }

        [$within, $ceiling] = $manager->burstWindow($activity->object_type, (string) $activity->verb);
        $at = $activity->published_at ?? now();
        // Every publisher acquires shared keys in the same order. Upserting
        // first avoids locking an empty search/gap on MySQL and MariaDB.
        ksort($pending);
        foreach ($pending as $key => [$axis, $prefix]) {
            $connection->table($table)->upsert(
                ['key' => $key, 'locked_at' => now()], ['key'], ['locked_at'],
            );
            $state = $connection->table($table)->where('key', $key)->lockForUpdate()->first();
            $opened = $state->opened_at === null ? null : Carbon::parse($state->opened_at);
            $last = $state->last_activity_at === null ? null : Carbon::parse($state->last_activity_at);
            $samePolicy = (int) $state->within_seconds === $within && (int) $state->ceiling_seconds === $ceiling;
            $joins = $samePolicy && $opened !== null && $last !== null
                && $at->greaterThanOrEqualTo($opened)
                && $at->lessThan($last->copy()->addSeconds($within))
                && $at->lessThan($opened->copy()->addSeconds($ceiling));

            $hashes[$axis] = $joins ? $state->hash : $prefix.$activity->uid;

            // Late arrivals never rewind/merge a closed burst. A late event
            // before the current opening gets its own immutable row; replay
            // history chronologically with curate --rebuild-bursts to regroup.
            if ($opened !== null && $at->lessThan($opened)) {
                continue;
            }
            $connection->table($table)->where('key', $key)->update([
                'hash' => $hashes[$axis],
                'opened_at' => Chronology::stamp($joins ? $opened : $at),
                'last_activity_at' => Chronology::stamp($joins && $last->greaterThan($at) ? $last : $at),
                'within_seconds' => $within,
                'ceiling_seconds' => $ceiling,
            ]);
        }

        return $hashes;
    }
}
