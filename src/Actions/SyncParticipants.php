<?php

namespace Storyfeed\Actions;

use Illuminate\Support\Facades\DB;
use Storyfeed\Models\Activity;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\Ancestors;
use Storyfeed\Support\Chronology;

/**
 * Materialize one row per (activity, entity identity) into feed_participants.
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
 *
 * @internal
 */
class SyncParticipants
{
    /** The roles an activity can fill. Each is 0-or-1 per activity row. */
    public const ROLES = ActivityRoles::STORED;

    /**
     * @param  bool  $inserted  the activity was inserted by the publish that
     *                          is calling, so it has no rows to rewrite
     */
    public function __invoke(Activity $activity, bool $inserted = false, bool $currentParents = false): void
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

            $identity = $type."\0".$id;
            if (isset($rows[$identity])) {
                continue;
            }
            $rows[$identity] = [
                'activity_id' => $key,
                'role' => $role,
                'distance' => 0,
                // Already an alias on the column — never re-derive it from a
                // class name, or an app that enforces a morph map stores one
                // vocabulary and queries another.
                'entity_type' => $type,
                'entity_id' => (string) $id,
                // Through the activity's own format, or the query builder
                // binds it at whole seconds and the copy disagrees with its
                // source inside every second.
                'published_at' => $activity->published_at === null ? null : Chronology::stamp($activity->published_at),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $ancestors = [];
        $walker = new Ancestors;
        foreach (Ancestors::ROLES as $role) {
            $type = $activity->getAttribute("{$role}_type");
            $id = $activity->getAttribute("{$role}_id");
            if ($type !== null && $id !== null) {
                $ancestors = [...$ancestors, ...$walker->walk($type, $id, $activity->getAttribute("cached_{$role}_id"), $currentParents)['rows']];
            }
        }
        foreach ($ancestors as $ancestor) {
            $identity = $ancestor['entity_type']."\0".$ancestor['entity_id'];
            if (isset($rows[$identity]) && $rows[$identity]['distance'] <= $ancestor['distance']) {
                continue;
            }
            $rows[$identity] = array_merge($ancestor, [
                'activity_id' => $key, 'role' => 'ancestor',
                'published_at' => $activity->published_at === null ? null : Chronology::stamp($activity->published_at),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Rewrite rather than diff: an activity edited to drop a role must
        // stop being findable by it, and the roles are cheap to re-derive.
        //
        // Not for a row the calling publish just inserted: it has nothing to delete,
        // and on InnoDB under REPEATABLE READ deleting nothing still locks the
        // gap at the end of the index, where every new activity's rows go. Two
        // concurrent publishes, by any actors, then each hold that gap and
        // wait to insert into it, and one dies with a deadlock (todo 1339).
        if (! $inserted) {
            DB::table($table)->where('activity_id', $key)->delete();
        }

        if ($rows !== []) {
            DB::table($table)->insert(array_values($rows));
        }
    }

    /**
     * Rewrite direct identities in bounded queries while keeping recorded
     * ancestor rows through tombstone/restore. No model walk belongs here.
     *
     * @param  list<int|string>  $ids
     */
    public static function directMany(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $participants = self::table();
        $activity = new (config('storyfeed.models.activity', Activity::class));
        $activities = $activity->getTable();
        DB::table($participants)->whereIn('activity_id', $ids)->where('role', '<>', 'ancestor')->delete();
        // A direct role wins over a recorded ancestor of the same identity.
        DB::table($participants)->whereIn('activity_id', $ids)->whereExists(function ($query) use ($participants, $activities): void {
            $query->from($activities)->whereColumn("{$activities}.id", "{$participants}.activity_id")
                ->where(function ($query) use ($participants, $activities): void {
                    foreach (self::ROLES as $role) {
                        $query->orWhere(fn ($query) => $query
                            ->whereColumn("{$activities}.{$role}_type", "{$participants}.entity_type")
                            ->whereColumn("{$activities}.{$role}_id", "{$participants}.entity_id"));
                    }
                });
        })->delete();
        foreach (self::ROLES as $role) {
            DB::table($participants)->insertOrIgnoreUsing(
                ['activity_id', 'role', 'entity_type', 'entity_id', 'distance', 'published_at', 'created_at', 'updated_at'],
                DB::table($activities)->whereIn('id', $ids)->whereNotNull("{$role}_type")->whereNotNull("{$role}_id")
                    ->select(['id'])->selectRaw('? as role', [$role])->addSelect(["{$role}_type", "{$role}_id"])
                    ->selectRaw('0 as distance')->addSelect(['published_at', 'created_at', 'updated_at']),
            );
        }
    }

    /**
     * Copy an activity's published_at onto its rows after it was
     * rescheduled. involving() orders, pages and gates on that copy.
     */
    public static function retime(Activity $activity): void
    {
        DB::table(self::table())->where('activity_id', $activity->getKey())->update([
            'published_at' => $activity->published_at === null ? null : Chronology::stamp($activity->published_at),
        ]);
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
