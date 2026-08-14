<?php

namespace Storyfeed\Actions;

use Illuminate\Support\Facades\DB;
use Storyfeed\Models\Activity;

/**
 * Materialize one row per (activity, filled role) into feed_participants.
 *
 * This is what makes `involving($model)` an indexed lookup instead of a
 * four-branch OR across morph pairs. Every prior generation of this feed
 * expressed "activities involving X" as that OR, and no schema ever indexed
 * it — the OR survived only because its sole caller was cascade-delete, where
 * latency does not matter. As a READ it needs an index, and an OR cannot have
 * one: each branch would need its own (role_type, role_id, published_at)
 * composite, and the planner still cannot use them for the ordering.
 *
 * `published_at` is denormalized so the lookup narrows, orders and pages from
 * one index without touching the activities table.
 *
 * Idempotent: safe from publish, from the backfill command, and from a repair.
 */
class SyncParticipants
{
    /** The roles an activity can fill. Each is 0-or-1 per activity row. */
    public const ROLES = ['actor', 'object', 'target', 'context'];

    public function __invoke(Activity $activity): void
    {
        $table = self::table();
        $key = $activity->getKey();

        $rows = [];

        foreach (self::ROLES as $role) {
            $type = $activity->getAttribute("{$role}_type");
            $id = $activity->getAttribute("{$role}_id");

            if ($type === null || $id === null) {
                continue;
            }

            $rows[] = [
                'activity_id' => $key,
                'role' => $role,
                // Already an alias on the column — never re-derive it from a
                // class name, or an app that enforces a morph map stores one
                // vocabulary and queries another.
                'entity_type' => $type,
                'entity_id' => (string) $id,
                'published_at' => $activity->published_at,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        // Rewrite rather than diff: an activity edited to drop a role must
        // stop being findable by it, and the roles are cheap to re-derive.
        DB::table($table)->where('activity_id', $key)->delete();

        if ($rows !== []) {
            DB::table($table)->insert($rows);
        }
    }

    /** Remove an activity's rows — cascade for prune and orphan-delete. */
    public static function forget(int|string ...$activityIds): void
    {
        if ($activityIds === []) {
            return;
        }

        DB::table(self::table())->whereIn('activity_id', $activityIds)->delete();
    }

    public static function table(): string
    {
        return config('storyfeed.tables.participants', 'feed_participants');
    }
}
