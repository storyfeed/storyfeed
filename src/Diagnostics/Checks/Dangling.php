<?php

namespace Storyfeed\Diagnostics\Checks;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * Grouping and participant rows whose activity no longer exists — trashed
 * included — counted, so a number nothing else will ever surface is a fact
 * an operator can read (todo 662).
 *
 * WHERE THEY COME FROM. There is no DB-level cascade from activities to the
 * rows that point at them, by design, and a bulk `forceDelete()` fires no
 * model events. So every path that hard-deletes activities by query has to
 * clear those rows itself, and until 2026-09-05 one of them did not:
 * `forceDeleteFromFeed()`, the hook that fires whenever a `Feedable` model is
 * force-deleted, left every grouping and participant row for that model's
 * activities pointing at a primary key that was gone. That path now goes
 * through `Actions\ForgetActivities` like prune and force-mode supersede,
 * but the rows it already left are still in the table, and nothing in the
 * package will ever mention them: the read path only reaches a grouping or a
 * participant row through a live activity, so they are unreachable, and
 * `storyfeed:prune` walks activities rather than their rows, so it never
 * sees them either. Invisible to the feed and invisible to the sweeper is
 * exactly the shape doctor exists for.
 *
 * "DANGLING", NOT "ORPHAN", because the package already uses the second
 * word: the trickle calls an ACTIVITY an orphan when the entity it names
 * has gone, and `storyfeed:trickle --prune` retires orphans in that sense.
 * A check named `orphans` that counted a different thing — rows whose
 * activity has gone — would send the reader to the wrong command. A pointer
 * whose target is gone is dangling; the word is standard and it is unused
 * here.
 *
 * INFO, NOT WARNING, and this is the decision that matters. Nothing renders
 * wrong because of these rows. No feed is shorter or longer, no entity page
 * is missing anything, no query returns a row it should not — the rows are
 * dead, not misleading. Warning is the level that trips `--fail-on=warning`
 * in CI, and it means "something is wrong or will silently degrade"; a dead
 * row that will never be read is neither. There is a second reason, and it
 * is about the reader rather than the rows: the only thing a Warning would
 * ask of them is a deletion, and the package deliberately does not offer
 * one (below). A Warning with no remedy is the finding people learn to
 * scroll past, and it teaches them to scroll past the next one.
 *
 * NO FIX, on purpose. `Fix` carries a registry edit — a snippet doctor can
 * print with `--stubs` — and there is no registry edit that resolves a dead
 * row. The only remedy is deleting it, and whether the package should ever
 * delete these rows is a decision that has not been made: the operator who
 * raised the surrounding family of findings runs an operations vault under a
 * standing rule of no pruning at all, and a check that offered to delete
 * from that vault would be answering a question nobody asked it. So this
 * check counts, says what the count means, and stops. That is not the same
 * as being useless — the count is the evidence that the family of bulk
 * paths is behaving, and a number that GROWS after the fix is the signal
 * that some other path has started leaving rows behind.
 *
 * COST. Two `COUNT(*) ... WHERE NOT EXISTS` queries, each a walk of one
 * dependent table against the activities primary key. That is a full scan
 * of `feed_groupings` and `feed_participants`, which no read-path query
 * would be allowed to do — but this runs on `storyfeed:doctor`, not on a
 * page, and both tables carry an index on `activity_id`. The activities
 * side is queried through the query builder rather than the model, so the
 * SoftDeletes scope does not apply: a row pointing at a trashed activity is
 * NOT dangling, because prune will take them together.
 *
 * Silent on a healthy install: no dead rows, no finding. Silent when a table
 * is missing, because `tables` has already said so.
 */
class Dangling extends Check
{
    public function name(): string
    {
        return 'dangling';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        $activities = $this->table('activities');

        if (! Schema::hasTable($activities)) {
            return; // Tables already reported it
        }

        yield from $this->count(
            'dangling.groupings',
            'grouping',
            $this->table('groupings'),
            $activities,
            'Nothing reads a grouping except through the live activity it points at, so they are inert on every '
            .'feed and every group; `storyfeed:prune` walks activities rather than their rows, so it does not '
            .'sweep them.',
        );

        yield from $this->count(
            'dangling.participants',
            'participant',
            SyncParticipants::table(),
            $activities,
            '`involving()` joins them to activities that must exist, so they match nothing and change no entity '
            .'page; `storyfeed:prune` walks activities rather than their rows, so it does not sweep them.',
        );
    }

    /**
     * Rows in `$table` whose `activity_id` is absent from activities, trashed
     * included — one finding when there are any, none when there are not.
     *
     * @return iterable<Finding>
     */
    protected function count(string $code, string $noun, string $table, string $activities, string $consequence): iterable
    {
        if (! Schema::hasTable($table)) {
            return; // Tables already reported it
        }

        $dangling = DB::table($table)
            ->whereNotExists(fn ($sub) => $sub
                ->from($activities)
                ->whereColumn("{$activities}.id", "{$table}.activity_id"))
            ->count();

        if ($dangling === 0) {
            return;
        }

        yield Finding::info(
            $code,
            "{$dangling} {$noun} ".str('row')->plural($dangling).' in `'.$table.'` point at '
            .str('activity')->plural($dangling).' that no longer exist, trashed included. '.$consequence
            .' The usual source is a `Feedable` force-deleted before `forceDeleteFromFeed()` cleared its rows; '
            .'that path now does, so this number should not grow — if it does, some other hard-delete path is '
            .'leaving rows behind. Nothing here is broken.',
            ['table' => $table, 'dangling' => $dangling],
        );
    }
}
