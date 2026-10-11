<?php

namespace Storyfeed\Tests\Fixtures;

use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Storyfeed\FeedBuilder;
use Storyfeed\FeedCandidate;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\Grouping;
use Storyfeed\Support\ActivityRoles;

/** The pre-optimization SQL, retained as a complete-payload equivalence oracle. */
final class ReadPathOracle extends FeedBuilder
{
    protected function windowDepths(): array
    {
        return [$this->limit * 16, $this->limit * 256, null];
    }

    protected function selectItems(Carbon $now): Collection
    {
        $cursor = $this->cursorState();

        return $this->groupStream($now, $cursor)
            ->concat($this->soloStream($now, $cursor))
            ->sort(fn (FeedCandidate $a, FeedCandidate $b): int => strcmp($b->latest, $a->latest)
                ?: ($this->rank($a) <=> $this->rank($b))
                ?: $this->compareTiebreak($a, $b))
            ->values();
    }

    protected function groupAggregate(Carbon $now, ?array $cursor, ?string $floor, ?string $ceiling): Collection
    {
        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $grammar = $this->groupingModel()->getConnection()->getQueryGrammar();
        $bucketColumn = $grammar->wrapTable($groupings).'.'.$grammar->wrap('bucket');
        $hashColumn = $grammar->wrapTable($groupings).'.'.$grammar->wrap('hash');
        $latest = 'max(fa.fa_published)';
        $lastId = 'max(fa.fa_id)';

        $filtered = $this->filteredActivities($now)
            ->when($floor !== null, fn (ActivityBuilder $q) => $q->where("{$activities}.published_at", '>=', $floor))
            ->when($ceiling !== null, fn (ActivityBuilder $q) => $q->where("{$activities}.published_at", '<=', $ceiling))
            ->select(["{$activities}.id as fa_id", "{$activities}.published_at as fa_published"]);

        $query = $this->groupingModel()->newQuery()
            ->where($this->winning())
            ->joinSub($filtered, 'fa', fn (JoinClause $join) => $join->on('fa.fa_id', '=', "{$groupings}.activity_id"))
            ->groupBy("{$groupings}.bucket", "{$groupings}.hash")
            ->select(["{$groupings}.bucket", "{$groupings}.hash"])
            ->selectRaw("{$latest} as latest")
            ->selectRaw("{$lastId} as last_id")
            ->selectRaw('count(*) as members')
            ->toBase();

        // Groups rank before solos at an identical timestamp, so a solo
        // cursor has already consumed every group in that tie.
        if ($cursor !== null && $cursor['rank'] === self::RANK_GROUP) {
            $query->havingRaw(
                "({$latest} < ? or ({$latest} = ? and ({$lastId} < ? or ({$lastId} = ? and ({$bucketColumn} > ? or ({$bucketColumn} = ? and {$hashColumn} > ?))))))",
                [$cursor['latest'], $cursor['latest'], $cursor['id'], $cursor['id'], $cursor['axis'], $cursor['axis'], $cursor['hash']],
            );
        } elseif ($cursor !== null) {
            $query->havingRaw("{$latest} < ?", [$cursor['latest']]);
        }

        if ($ceiling === null) {
            return $query
                ->orderByRaw("{$latest} desc")
                ->orderByRaw("{$lastId} desc")
                ->orderBy("{$groupings}.bucket")
                ->orderBy("{$groupings}.hash")
                ->limit($this->limit + 1)
                ->get();
        }

        // Windowed under a cursor: drop every group with an eligible member
        // newer than the ceiling — its true latest is above the cursor, and
        // it has already been paged. Wrapping (rather than a HAVING
        // subquery) keeps the correlated reference on plain derived-table
        // columns, which every supported grammar accepts.
        $newer = $this->winningMembers($now)
            ->whereColumn("{$groupings}.bucket", 'windowed.bucket')
            ->whereColumn("{$groupings}.hash", 'windowed.hash')
            ->where("{$activities}.published_at", '>', $ceiling)
            ->selectRaw('1')
            ->toBase();

        return $this->groupingModel()->getConnection()->query()
            ->fromSub($query, 'windowed')
            ->whereNotExists($newer)
            ->orderBy('latest', 'desc')
            ->orderBy('last_id', 'desc')
            ->orderBy('bucket')
            ->orderBy('hash')
            ->limit($this->limit + 1)
            ->get();
    }

    protected function winning(?string $bucket = null): Closure
    {
        $groupings = $this->groupingModel()->getTable();

        $curate = config('storyfeed.grouping.curate', true);

        return function ($query) use ($groupings, $curate) {
            $query->where(fn ($winner) => $winner
                ->where("{$groupings}.winner", true)
                ->when(! $curate, fn ($claim) => $claim->where("{$groupings}.bucket", 'composite')))
                ->orWhere(fn ($fallback) => $fallback
                    ->where("{$groupings}.bucket", 'repeat')
                    ->whereNotExists(fn (QueryBuilder $sub) => $sub
                        ->selectRaw('1')
                        ->from("{$groupings} as w")
                        ->whereColumn('w.activity_id', "{$groupings}.activity_id")
                        ->where('w.winner', true)
                        ->when(! $curate, fn (QueryBuilder $claim) => $claim->where('w.bucket', 'composite'))));
        };
    }

    protected function soloStream(Carbon $now, ?array $cursor, ?string $floor = null): Collection
    {
        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $query = $this->filteredActivities($now);

        // "Has no winning grouping row", SPLIT INTO ONE ANTIJOIN PER DISJUNCT
        // rather than one antijoin over `winning()`. See notSolo() for why the
        // split is exactly equivalent; this is the measured half of it.
        //
        // MEASURED ON MYSQL 8.4.11, 50k activities, page 1: 263ms -> 109ms,
        // taking the whole page from 689ms to 529ms (W103, 2026-09-09, see
        // docs/held/w103-groupstream.md). This query proves a NEGATIVE over
        // history, so it is at its most expensive when every activity IS
        // curated and it returns nothing — the healthy steady state, and the
        // read every page load pays for. The single `where($this->winning())`
        // form put an OR and a correlated subquery inside the antijoin, which
        // blocks a covering index: MySQL read ~5 grouping ROWS per activity
        // from the clustered index, 50,000 times. Split, each disjunct is a
        // covering index lookup on an existing index, and the first one that
        // matches short-circuits the rest.
        foreach ($this->notSolo() as $constraint) {
            $query->whereNotExists(fn (QueryBuilder $sub) => $constraint($sub
                ->selectRaw('1')
                ->from($groupings)
                ->whereColumn("{$groupings}.activity_id", "{$activities}.id")));
        }

        $query
            // Composite parents and members are never solo: the parent is
            // told by its cluster node, the members by their composite. In
            // the digest the parent IS the telling and takes its person's
            // row; one written before partition rows existed has none, and
            // reads solo here until the trickle or `--rehash` writes it.
            ->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->selectRaw('1')
                ->from("{$groupings} as composite_rows")
                ->whereColumn('composite_rows.activity_id', "{$activities}.id")
                ->where('composite_rows.bucket', 'composite')
            )
            ->with(ActivityRoles::cachedRelations())
            ->orderBy("{$activities}.published_at", 'desc')
            ->orderBy("{$activities}.id", 'desc')
            ->limit($this->limit + 1);

        if ($cursor !== null && $cursor['rank'] === self::RANK_SOLO) {
            $query->where(fn (ActivityBuilder $q) => $q
                ->where("{$activities}.published_at", '<', $cursor['latest'])
                ->orWhere(fn (ActivityBuilder $tie) => $tie
                    ->where("{$activities}.published_at", '=', $cursor['latest'])
                    ->where("{$activities}.id", '<', $cursor['id'])));
        } elseif ($cursor !== null) {
            // Every group at this timestamp is spent; solos in the tie remain.
            $query->where("{$activities}.published_at", '<=', $cursor['latest']);
        }

        return $query->get()->map(fn (Activity $activity) => FeedCandidate::solo(
            $this->normalizeTimestamp($activity->published_at),
            $activity,
        ));
    }

    protected function selectedGroupMembers(Carbon $now, Collection $groups): ActivityBuilder
    {
        $groupings = $this->groupingModel()->getTable();

        return $this->winningMembers($now)
            ->where(function ($query) use ($groupings, $groups) {
                foreach ($groups as $group) {
                    $query->orWhere(fn ($pair) => $pair
                        ->where("{$groupings}.bucket", $group->axis)
                        ->where("{$groupings}.hash", $group->hash));
                }
            });
    }
}
