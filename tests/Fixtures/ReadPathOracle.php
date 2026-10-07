<?php

namespace Storyfeed\Tests\Fixtures;

use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Storyfeed\FeedBuilder;
use Storyfeed\FeedCandidate;
use Storyfeed\Grouping\Period;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Grouping;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\StoryfeedManager;
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
            ->selectRaw('count(*) as members')
            ->toBase();

        // Groups rank before solos at an identical timestamp, so a solo
        // cursor has already consumed every group in that tie.
        if ($cursor !== null && $cursor['rank'] === self::RANK_GROUP) {
            $query->havingRaw(
                "({$latest} < ? or ({$latest} = ? and ({$bucketColumn} > ? or ({$bucketColumn} = ? and {$hashColumn} > ?))))",
                [$cursor['latest'], $cursor['latest'], $cursor['axis'], $cursor['axis'], $cursor['hash']],
            );
        } elseif ($cursor !== null) {
            $query->havingRaw("{$latest} < ?", [$cursor['latest']]);
        }

        if ($ceiling === null) {
            return $query
                ->orderByRaw("{$latest} desc")
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
            ->orderBy('bucket')
            ->orderBy('hash')
            ->limit($this->limit + 1)
            ->get();
    }

    protected function winning(?string $bucket = null): Closure
    {
        $groupings = $this->groupingModel()->getTable();

        if ($this->mode() === 'summary') {
            $bucket = $this->summaryBucket();

            return fn ($query) => $query->where("{$groupings}.bucket", $bucket);
        }

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
                ->when($this->mode() === 'summary', fn (QueryBuilder $members) => $members->where('composite_rows.winner', true)))
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

    protected function summarySlices(Carbon $now, Collection $candidates, Collection $groups): Collection
    {
        $units = $this->crowds($now, $groups);
        $members = $this->fetchMembers($now, $groups, byVerb: true);
        $aggregates = $this->summaryAggregates($now, $groups, $units);

        $counts = [];
        $keys = [];

        foreach ($groups as $group) {
            $key = $this->groupKey($group);
            $unit = $units[$key];
            $counts[$unit] = ($counts[$unit] ?? 0) + $group->count;
            $keys[$unit][] = $key;
        }

        $emitted = [];
        $slices = [];

        foreach ($candidates as $candidate) {
            if ($candidate->activity !== null) {
                $slices[] = GroupSlice::solo($candidate->activity);

                continue;
            }

            $unit = $units[$this->groupKey($candidate)];

            // A crowd sits where its newest person would have.
            if (isset($emitted[$unit])) {
                continue;
            }

            $emitted[$unit] = true;

            /** @var EloquentCollection<int, Activity> $all */
            $all = $this->activityModel()->newCollection(
                Collection::make($keys[$unit])
                    ->flatMap(fn (string $key) => $members->get($key)?->all() ?? [])
                    ->sort(fn (Activity $a, Activity $b): int => $this->normalizeTimestamp($b->published_at) <=> $this->normalizeTimestamp($a->published_at)
                        ?: $b->getKey() <=> $a->getKey())
                    ->values()
                    ->all(),
            );

            if ($all->isEmpty()) {
                continue; // deleted between the phases; see groupSlices()
            }

            $phrases = Collection::make($aggregates['phrases'][$unit] ?? [])
                ->map(fn (array $phrase, int|string $verb) => [
                    'verb' => (string) $verb,
                    'count' => $phrase['count'],
                    'first' => $phrase['first'],
                    'distinct' => $phrase['distinct'],
                    'members' => $all->filter(fn (Activity $a) => $a->verb === (string) $verb)->values(),
                ])
                // In the order the period happened: each verb where it first
                // occurred ("checked in, got a balloon and went on 3 rides"),
                // read from every member, not the capped ones. Then the verb,
                // so the order never depends on the database's.
                ->sort(fn (array $a, array $b): int => strcmp($a['first'], $b['first'])
                    ?: strcmp($a['verb'], $b['verb']))
                ->map(fn (array $phrase) => array_diff_key($phrase, ['first' => true]))
                ->values()
                ->all();

            $slices[] = GroupSlice::summary(
                (string) $candidate->axis,
                count($keys[$unit]) === 1 ? (string) $candidate->hash : 'crowd'."\x1f".implode("\x1e", array_map(
                    fn (string $key) => explode("\x1f", $key, 2)[1],
                    $keys[$unit],
                )),
                $this->period,
                $counts[$unit],
                $all->take($this->childrenLimit())->values(),
                $aggregates['distinct'][$unit] ?? [],
                $aggregates['tombstoned'][$unit] ?? [],
                $phrases,
            );
        }

        return Collection::make($slices);
    }

    protected function summaryAggregates(Carbon $now, Collection $groups, array $units): array
    {
        $result = ['distinct' => [], 'tombstoned' => [], 'phrases' => []];

        if ($groups->isEmpty()) {
            return $result;
        }

        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();
        $connection = $this->activityModel()->getConnection();
        [$unit, $bindings] = $this->unitColumn($groups, $units);

        $members = fn (array $columns) => $this->selectedGroupMembers($now, $groups)
            ->toBase()
            ->selectRaw("{$unit} as unit_key", $bindings)
            ->addSelect($columns);

        $counts = $connection->query()
            ->fromSub($members(["{$activities}.verb", "{$activities}.published_at"]), 'm')
            ->groupBy('unit_key', 'verb')
            ->select(['unit_key', 'verb'])
            ->selectRaw('count(*) as total')
            ->selectRaw('min(published_at) as first_at')
            ->get();

        foreach ($counts as $row) {
            $result['phrases'][$row->unit_key][$row->verb] = [
                'count' => (int) $row->total,
                'first' => $this->normalizeTimestamp($row->first_at),
                'distinct' => [],
            ];
        }

        foreach (ActivityRoles::GROUPABLE as $role) {
            $identity = ["{$activities}.{$role}_type", "{$activities}.{$role}_id"];

            $perPhrase = $connection->query()
                ->fromSub($members(["{$activities}.verb", ...$identity])->whereNotNull("{$activities}.{$role}_type")->distinct(), 'd')
                ->groupBy('unit_key', 'verb')
                ->select(['unit_key', 'verb'])
                ->selectRaw('count(*) as total')
                ->get();

            foreach ($perPhrase as $row) {
                if (isset($result['phrases'][$row->unit_key][$row->verb])) {
                    $result['phrases'][$row->unit_key][$row->verb]['distinct'][$role] = (int) $row->total;
                }
            }

            $perRow = $connection->query()
                ->fromSub($members($identity)->whereNotNull("{$activities}.{$role}_type")->distinct(), 'd')
                ->groupBy('unit_key')
                ->select(['unit_key'])
                ->selectRaw('count(*) as total')
                ->selectRaw("sum(case when {$role}_type = ? then 1 else 0 end) as tombstoned", [FeedTombstone::MORPH_ALIAS])
                ->get();

            foreach ($perRow as $row) {
                $result['distinct'][$row->unit_key][$role] = (int) $row->total;
                $result['tombstoned'][$row->unit_key][$role] = (int) $row->tombstoned;
            }
        }

        return $result;
    }

    protected function presenter(): NodePresenter
    {
        return (new ReadPathPresenterOracle(app(StoryfeedManager::class)))->forFeed($this->feed);
    }

    protected function unitColumn(Collection $groups, array $units): array
    {
        $groupings = $this->groupingModel()->getTable();
        $grammar = $this->groupingModel()->getConnection()->getQueryGrammar();
        $bucket = $grammar->wrapTable($groupings).'.'.$grammar->wrap('bucket');
        $hash = $grammar->wrapTable($groupings).'.'.$grammar->wrap('hash');

        $sql = 'case';
        $bindings = [];

        foreach ($groups as $group) {
            $sql .= " when {$bucket} = ? and {$hash} = ? then ?";
            array_push($bindings, (string) $group->axis, (string) $group->hash, $units[$this->groupKey($group)]);
        }

        return [$sql.' end', $bindings];
    }

    protected function fetchMembers(Carbon $now, Collection $groups, bool $byVerb = false): Collection
    {
        if ($groups->isEmpty()) {
            return Collection::make();
        }

        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        // A digest row caps per VERB, so a busy day's first 25 children
        // cannot all be one verb and leave the other phrases no sample.
        $grammar = $this->activityModel()->getConnection()->getQueryGrammar();
        $partition = sprintf(
            'row_number() over (partition by %s, %s%s order by %s desc, %s desc) as member_rank',
            $grammar->wrapTable($groupings).'.'.$grammar->wrap('bucket'),
            $grammar->wrapTable($groupings).'.'.$grammar->wrap('hash'),
            $byVerb ? ', '.$grammar->wrapTable($activities).'.'.$grammar->wrap('verb') : '',
            $grammar->wrapTable($activities).'.'.$grammar->wrap('published_at'),
            $grammar->wrapTable($activities).'.'.$grammar->wrap('id'),
        );

        $ranked = $this->selectedGroupMembers($now, $groups)
            ->select([
                "{$activities}.*",
                "{$groupings}.bucket as group_bucket",
                "{$groupings}.hash as group_hash",
            ])
            ->selectRaw($partition);

        $rows = $this->activityModel()->getConnection()->query()
            ->fromSub($ranked, 'ranked')
            ->where('member_rank', '<=', $this->childrenLimit())
            ->orderBy('published_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $members = $this->activityModel()->newQuery()->hydrate($rows->all());

        $members->load(ActivityRoles::cachedRelations());

        return $members->groupBy(fn (Activity $activity) => $activity->group_bucket."\x1f".$activity->group_hash);
    }

    protected function crowds(Carbon $now, Collection $groups): array
    {
        $units = [];

        foreach ($groups as $group) {
            $units[$this->groupKey($group)] = $this->groupKey($group);
        }

        if ($groups->count() < 2) {
            return $units;
        }

        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $shapes = $this->activityModel()->getConnection()->query()
            ->fromSub($this->selectedGroupMembers($now, $groups)->toBase()->select([
                "{$groupings}.bucket as group_bucket",
                "{$groupings}.hash as group_hash",
                "{$activities}.verb",
                "{$activities}.target_type",
                "{$activities}.target_id",
            ]), 'm')
            ->groupBy('group_bucket', 'group_hash')
            ->select(['group_bucket', 'group_hash'])
            ->selectRaw('count(*) as members')
            ->selectRaw('min(verb) as verb')
            ->selectRaw('count(target_type) as targeted')
            ->selectRaw('min(target_type) as min_type, max(target_type) as max_type')
            ->selectRaw('min(target_id) as min_id, max(target_id) as max_id')
            ->get();

        $crowds = [];

        foreach ($shapes as $shape) {
            $untargeted = (int) $shape->targeted === 0;
            $oneTarget = (int) $shape->targeted === (int) $shape->members
                && $shape->min_type === $shape->max_type
                && (string) $shape->min_id === (string) $shape->max_id;

            // One activity, not one kind of activity: a crowd row's count is
            // its number of people, so ":count times" on it stays honest.
            if ((int) $shape->members !== 1 || ! ($untargeted || $oneTarget)) {
                continue;
            }

            // Same period VALUE, not just the same bucket: the day is the
            // key's last segment (`user:7:2026-09-25`). A key long enough to
            // be digested has lost it, and keeps its own row.
            $hash = (string) $shape->group_hash;

            if (! str_contains($hash, ':')) {
                continue;
            }

            $thing = implode("\x1f", [$shape->group_bucket, substr($hash, strrpos($hash, ':') + 1), $shape->verb, $untargeted ? '' : $shape->min_type, $untargeted ? '' : (string) $shape->min_id]);

            $crowds[$thing][] = $shape->group_bucket."\x1f".$shape->group_hash;
        }

        foreach ($crowds as $keys) {
            if (count($keys) < 2) {
                continue;
            }

            // The unit is the crowd's first person in page order.
            $first = collect(array_keys($units))->first(fn (string $key) => in_array($key, $keys, true));

            foreach ($keys as $key) {
                $units[$key] = (string) $first;
            }
        }

        return $units;
    }
}
