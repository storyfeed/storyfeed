<?php

namespace Storyfeed;

use Closure;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Traits\Conditionable;
use InvalidArgumentException;
use Storyfeed\Grouping\NullStrategy;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Party;
use Storyfeed\Payload\FeedPage;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Support\SyncToken;

/**
 * Fluent reader for feeds.
 *
 *   Storyfeed::feed()->limit(30)->get();
 *   Storyfeed::feed()->context($project)->get();
 *   Storyfeed::feed()->actor($user)->limit(15)->get();
 *
 * Three read modes, each naming what you get (docs/grouping.md):
 *
 *   ->flat()      a log of atomic activities, no group nodes
 *   ->grouped()   repeat-only grouping ("Sally uploaded 12 photos")
 *   ->curated()   multi-axis winners ("Bob, Sally and 3 others uploaded…")
 *
 * The shipped default is curated; apps override via `grouping.default`.
 *
 * READ STRATEGY (docs/grouping.md). The page is selected in two phases:
 *
 *  1. Select `limit` FEED ITEMS — not raw rows. Two ordered streams are
 *     merged in PHP: grouped (`feed_groupings` aggregated by hash, carrying
 *     MAX(published_at) and the true COUNT) and solo (activities with no
 *     grouping row, so legacy/imported rows degrade gracefully instead of
 *     vanishing). The cursor walks that merged stream, so "load more"
 *     reaches older groups.
 *  2. Fetch members for the selected groups, capped per group by
 *     `grouping.children_limit` via ROW_NUMBER().
 *
 * The curated, materialized read model (feed_groups) remains slated to
 * replace phase 1 behind the same opaque cursor — gated on benchmarks.
 */
class FeedBuilder
{
    use Conditionable;

    protected ?Model $actor = null;

    protected ?Model $object = null;

    protected ?Model $target = null;

    protected ?Model $context = null;

    protected ?Model $involving = null;

    protected ?string $verb = null;

    protected int $limit = 30;

    protected ?string $cursor = null;

    /** A named filter was requested but matched no party. */
    protected bool $unresolvable = false;

    /** Read mode: 'flat' | 'grouped' | 'curated'. Null = configured default. */
    protected ?string $mode = null;

    /** Ordering rank of each stream, applied at identical timestamps. */
    protected const RANK_GROUP = 0;

    protected const RANK_SOLO = 1;

    public function actor(Model|string $model): static
    {
        $this->actor = $this->resolve($model);

        return $this;
    }

    public function object(Model|string $model): static
    {
        $this->object = $this->resolve($model);

        return $this;
    }

    public function target(Model|string $model): static
    {
        $this->target = $this->resolve($model);

        return $this;
    }

    public function context(Model|string $model): static
    {
        $this->context = $this->resolve($model);

        return $this;
    }

    /**
     * Activities involving the model in any role.
     */
    public function for(Model|string $model): static
    {
        $this->involving = $this->resolve($model);

        return $this;
    }

    public function verb(string $verb): static
    {
        $this->verb = $verb;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    /**
     * A flat log of atomic activities — no group nodes at all. Log mode:
     * audit-style views ("my activity") often read better plain.
     */
    public function flat(): static
    {
        $this->mode = 'flat';

        return $this;
    }

    /**
     * Repeat-only grouping — "Sally uploaded 12 photos", never multi-axis.
     * The sensible middle tier, and the pre-package apps' proven behaviour.
     */
    public function grouped(): static
    {
        $this->mode = 'grouped';

        return $this;
    }

    /**
     * Multi-axis curated grouping — each activity under its winning axis
     * ("Bob, Sally and 3 others uploaded files to Concur"). The shipped
     * default; experimental in the sense that the policy keeps evolving.
     *
     * Cursors are mode-specific: never replay one across modes.
     */
    public function curated(): static
    {
        $this->mode = 'curated';

        return $this;
    }

    /**
     * The effective read mode: explicit per-view choice, else the app-wide
     * `grouping.default`. Unknown modes are errors, not features.
     */
    protected function mode(): string
    {
        $mode = $this->mode ?? (string) config('storyfeed.grouping.default', 'curated');

        if (! in_array($mode, ['flat', 'grouped', 'curated'], true)) {
            throw new InvalidArgumentException(
                "Unknown feed mode [{$mode}]. Valid modes: flat, grouped, curated.",
            );
        }

        return $mode;
    }

    /**
     * Continue from an opaque cursor returned in a previous page.
     */
    public function cursor(?string $cursor): static
    {
        $this->cursor = $cursor;

        return $this;
    }

    public function get(): FeedPage
    {
        // Captured once: the published() gate must not shift between the
        // group-selection query and the member fetch.
        $now = Carbon::now();

        return $this->shouldGroup()
            ? $this->groupedPage($now)
            : $this->flatPage($now);
    }

    /**
     * A string names a Party. On the read path this LOOKS UP only — a query
     * must never create rows — so an unknown name matches nothing.
     */
    protected function resolve(Model|string $model): ?Model
    {
        if (! is_string($model)) {
            return $model;
        }

        $party = config('storyfeed.models.party', Party::class);

        $resolved = $party::find($model);

        // Filtering by a name nobody has used must match nothing — not
        // silently drop the filter and return the whole feed.
        $this->unresolvable = $this->unresolvable || $resolved === null;

        return $resolved;
    }

    protected function shouldGroup(): bool
    {
        return $this->mode() !== 'flat'
            && ! is_a(config('storyfeed.grouping.strategy'), NullStrategy::class, true);
    }

    protected function groupedPage(Carbon $now): FeedPage
    {
        $candidates = $this->selectItems($now);

        $more = $candidates->count() > $this->limit;
        $candidates = $candidates->take($this->limit)->values();

        $next = $more ? $this->encodeCursor($candidates->last()) : null;

        $groups = $candidates->filter(fn (FeedCandidate $candidate) => $candidate->isGroup())->values();

        $members = $this->fetchMembers($now, $groups);
        $distinct = $this->countDistinctRoles($now, $groups);

        $slices = $candidates->map(function (FeedCandidate $candidate) use ($members, $distinct): GroupSlice {
            if ($candidate->activity !== null) {
                return GroupSlice::solo($candidate->activity);
            }

            $key = $this->groupKey($candidate);

            return GroupSlice::group(
                (string) $candidate->axis,
                (string) $candidate->hash,
                $candidate->count,
                $members->get($key) ?? $this->activityModel()->newCollection(),
                $distinct[$key] ?? [],
            );
        })
            // PHASE 2 IS AUTHORITATIVE (2026-08-12, found in the Newsroom's
            // production logs). The two phases run as separate queries, so a
            // candidate selected in phase 1 can have its activities deleted
            // before phase 2 hydrates them — a real race, not a bad state:
            // both phases share one soft-delete scope, so deleting up front
            // stays consistent and cannot reproduce it. The Newsroom hit it
            // during the shape-column drift, when an every-5-minute trickle
            // was taking the ORPHAN delete path over a 200-row budget while
            // open tabs polled every 10 seconds.
            //
            // An empty slice took down the WHOLE render, two ways: count == 1
            // (no HAVING floor in groupStream, so it happens) fails
            // isGroup() and passes null into activityNode(); count > 1
            // reaches groupNode() and dies on the head member. Guarding one
            // presenter line would have left the other crash live, so the
            // drop belongs here, at the boundary — and activityNode() keeps
            // its non-nullable Activity instead of pushing the empty case
            // into every caller.
            //
            // Dropping is the honest answer, not hiding: the activities are
            // genuinely gone, so omitting their node degrades gracefully the
            // way a missing snapshot does. `$next` is already computed above
            // from the unfiltered candidates, so pagination neither skips a
            // page nor stalls; a page that drops every slice still carries a
            // usable cursor.
            ->reject(fn (GroupSlice $slice) => $slice->members->isEmpty())
            ->values();

        return new FeedPage($slices, $next, app(NodePresenter::class), SyncToken::current());
    }

    protected function flatPage(Carbon $now): FeedPage
    {
        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $paginator = $this->filteredActivities($now)
            // Flat is the atomic timeline: composite MEMBERS appear, the
            // object-less parent STORY does not (its self-row marks it).
            ->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->selectRaw('1')
                ->from($groupings)
                ->whereColumn("{$groupings}.activity_id", "{$activities}.id")
                ->where("{$groupings}.bucket", 'composite')
                ->whereColumn("{$groupings}.hash", "{$activities}.uid"))
            ->with(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext'])
            ->orderBy("{$activities}.published_at", 'desc')
            ->orderBy("{$activities}.id", 'desc')
            ->cursorPaginate(perPage: $this->limit, cursor: $this->decodedCursor());

        $slices = Collection::make($paginator->items())
            ->map(fn (Activity $activity) => GroupSlice::solo($activity));

        return new FeedPage($slices, $paginator->nextCursor()?->encode(), app(NodePresenter::class), SyncToken::current());
    }

    /**
     * Phase 1 — select one page of FEED ITEMS by merging the grouped and solo
     * streams. Ordering is total by construction: (latest DESC, stream rank
     * ASC, hash|id ASC), which is what makes the cursor deterministic when
     * several items share a MAX(published_at) — routine on bulk imports.
     *
     * @return Collection<int, FeedCandidate>
     */
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

    protected function rank(FeedCandidate $candidate): int
    {
        return $candidate->isGroup() ? self::RANK_GROUP : self::RANK_SOLO;
    }

    /**
     * Only ever called for candidates of the same stream — the rank
     * comparison has already separated the two.
     *
     * Groups compare on (axis, hash), matching the SQL tuple comparison in
     * the cursor predicate exactly. Comparing on hash alone would assume no
     * two axes can ever produce the same hash string — true in practice,
     * but nothing enforces it.
     */
    protected function compareTiebreak(FeedCandidate $a, FeedCandidate $b): int
    {
        if ($a->hash !== null && $b->hash !== null) {
            return strcmp((string) $a->axis, (string) $b->axis) ?: strcmp($a->hash, $b->hash);
        }

        if ($a->activity !== null && $b->activity !== null) {
            return $a->activity->getKey() <=> $b->activity->getKey();
        }

        return 0;
    }

    /**
     * @param  array{latest: string, rank: int, axis: string|null, hash: string|null, id: int|string|null}|null  $cursor
     * @return Collection<int, FeedCandidate>
     */
    protected function groupStream(Carbon $now, ?array $cursor): Collection
    {
        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $grammar = $this->groupingModel()->getConnection()->getQueryGrammar();
        $bucketColumn = $grammar->wrapTable($groupings).'.'.$grammar->wrap('bucket');
        $hashColumn = $grammar->wrapTable($groupings).'.'.$grammar->wrap('hash');
        $latest = 'max(fa.fa_published)';

        $filtered = $this->filteredActivities($now)
            ->select(["{$activities}.id as fa_id", "{$activities}.published_at as fa_published"]);

        $query = $this->groupingModel()->newQuery()
            ->where($this->winning())
            ->joinSub($filtered, 'fa', fn (JoinClause $join) => $join->on('fa.fa_id', '=', "{$groupings}.activity_id"))
            ->groupBy("{$groupings}.bucket", "{$groupings}.hash")
            ->select(["{$groupings}.bucket", "{$groupings}.hash"])
            ->selectRaw("{$latest} as latest")
            ->selectRaw('count(*) as members')
            ->orderByRaw("{$latest} desc")
            ->orderBy("{$groupings}.bucket")
            ->orderBy("{$groupings}.hash")
            ->limit($this->limit + 1);

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

        return $query->get()->map(fn (Grouping $row) => FeedCandidate::group(
            $this->normalizeTimestamp($row->getAttribute('latest')),
            (string) $row->getAttribute('bucket'),
            (string) $row->getAttribute('hash'),
            (int) $row->getAttribute('members'),
        ));
    }

    /**
     * The grouping predicate, applied wherever the groupings table is in
     * play.
     *
     * grouped: the `repeat` axis, plain and proven.
     *
     * curated: `winner = true` is the curated answer, and a row with NO
     * winner stamped anywhere for its activity falls back to `repeat` —
     * so adopters upgrade into the winner column with no backfill cliff
     * (`storyfeed:curate` settles history incrementally), and an app with
     * curation stamping disabled reads as repeat-only.
     */
    protected function winning(): Closure
    {
        $groupings = $this->groupingModel()->getTable();

        if ($this->mode() === 'grouped') {
            // Authored stories (composites) belong in the classic tier too —
            // grouped mode excludes multi-axis INFERENCE, not declarations.
            return fn ($query) => $query
                ->where("{$groupings}.bucket", 'repeat')
                ->orWhere(fn ($composite) => $composite
                    ->where("{$groupings}.bucket", 'composite')
                    ->where("{$groupings}.winner", true));
        }

        return function ($query) use ($groupings) {
            $query->where("{$groupings}.winner", true)
                ->orWhere(fn ($fallback) => $fallback
                    ->where("{$groupings}.bucket", 'repeat')
                    ->whereNotExists(fn (QueryBuilder $sub) => $sub
                        ->selectRaw('1')
                        ->from("{$groupings} as w")
                        ->whereColumn('w.activity_id', "{$groupings}.activity_id")
                        ->where('w.winner', true)));
        };
    }

    /**
     * Activities carrying no winning grouping row at all (legacy, imported,
     * or awaiting the trickle). Their presence here is what keeps graceful
     * degradation true: the read path never hides an activity.
     *
     * @param  array{latest: string, rank: int, axis: string|null, hash: string|null, id: int|string|null}|null  $cursor
     * @return Collection<int, FeedCandidate>
     */
    protected function soloStream(Carbon $now, ?array $cursor): Collection
    {
        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $query = $this->filteredActivities($now)
            ->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->selectRaw('1')
                ->from($groupings)
                ->whereColumn("{$groupings}.activity_id", "{$activities}.id")
                ->where($this->winning()))
            // Composite parents and members are never solo: the parent is
            // told by its cluster node, the members by their composite.
            ->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->selectRaw('1')
                ->from("{$groupings} as composite_rows")
                ->whereColumn('composite_rows.activity_id', "{$activities}.id")
                ->where('composite_rows.bucket', 'composite'))
            ->with(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext'])
            ->orderBy("{$activities}.published_at", 'desc')
            ->orderBy("{$activities}.id")
            ->limit($this->limit + 1);

        if ($cursor !== null && $cursor['rank'] === self::RANK_SOLO) {
            $query->where(fn (ActivityBuilder $q) => $q
                ->where("{$activities}.published_at", '<', $cursor['latest'])
                ->orWhere(fn (ActivityBuilder $tie) => $tie
                    ->where("{$activities}.published_at", '=', $cursor['latest'])
                    ->where("{$activities}.id", '>', $cursor['id'])));
        } elseif ($cursor !== null) {
            // Every group at this timestamp is spent; solos in the tie remain.
            $query->where("{$activities}.published_at", '<=', $cursor['latest']);
        }

        return $query->get()->map(fn (Activity $activity) => FeedCandidate::solo(
            $this->normalizeTimestamp($activity->published_at),
            $activity,
        ));
    }

    /**
     * Phase 2 — members of the selected groups, newest first, capped per
     * group by ROW_NUMBER() so one 10k-member group cannot swamp a page.
     *
     * @param  Collection<int, FeedCandidate>  $groups
     * @return Collection<array-key, EloquentCollection<int, Activity>>
     */
    protected function fetchMembers(Carbon $now, Collection $groups): Collection
    {
        if ($groups->isEmpty()) {
            return Collection::make();
        }

        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $grammar = $this->activityModel()->getConnection()->getQueryGrammar();
        $partition = sprintf(
            'row_number() over (partition by %s, %s order by %s desc, %s desc) as member_rank',
            $grammar->wrapTable($groupings).'.'.$grammar->wrap('bucket'),
            $grammar->wrapTable($groupings).'.'.$grammar->wrap('hash'),
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

        $members->load(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext']);

        return $members->groupBy(fn (Activity $activity) => $activity->group_bucket."\x1f".$activity->group_hash);
    }

    /**
     * TRUE distinct counts per role per selected group — the source of the
     * payload's `distinct` block. They cannot be derived from `children`,
     * which is capped: a 200-actor group would otherwise report "and 22
     * more". One aggregate query per role (4/page — acceptable; the Step 3
     * read model absorbs this someday), each a subquery of distinct
     * (group, role) rows because multi-column COUNT(DISTINCT …) is not
     * portable.
     *
     * @param  Collection<int, FeedCandidate>  $groups
     * @return array<string, array<string, int>> groupKey => role => count
     */
    protected function countDistinctRoles(Carbon $now, Collection $groups): array
    {
        if ($groups->isEmpty()) {
            return [];
        }

        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $counts = [];

        foreach (['actor', 'object', 'target', 'context'] as $role) {
            $distinct = $this->selectedGroupMembers($now, $groups)
                ->whereNotNull("{$activities}.{$role}_type")
                ->select([
                    "{$groupings}.bucket as group_bucket",
                    "{$groupings}.hash as group_hash",
                    "{$activities}.{$role}_type",
                    "{$activities}.{$role}_id",
                ])
                ->distinct()
                ->toBase();

            $rows = $this->activityModel()->getConnection()->query()
                ->fromSub($distinct, 'd')
                ->groupBy('group_bucket', 'group_hash')
                ->select(['group_bucket', 'group_hash'])
                ->selectRaw('count(*) as total')
                ->get();

            foreach ($rows as $row) {
                $counts[$row->group_bucket."\x1f".$row->group_hash][$role] = (int) $row->total;
            }
        }

        return $counts;
    }

    /**
     * The filtered activities belonging to the selected groups, joined to
     * their winning grouping row.
     *
     * @param  Collection<int, FeedCandidate>  $groups
     */
    protected function selectedGroupMembers(Carbon $now, Collection $groups): ActivityBuilder
    {
        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        return $this->filteredActivities($now)
            ->join($groupings, fn (JoinClause $join) => $join
                ->on("{$groupings}.activity_id", '=', "{$activities}.id"))
            ->where($this->winning())
            ->where(function ($query) use ($groupings, $groups) {
                foreach ($groups as $group) {
                    $query->orWhere(fn ($pair) => $pair
                        ->where("{$groupings}.bucket", $group->axis)
                        ->where("{$groupings}.hash", $group->hash));
                }
            });
    }

    protected function groupKey(FeedCandidate $candidate): string
    {
        return $candidate->axis."\x1f".$candidate->hash;
    }

    protected function childrenLimit(): int
    {
        return (int) config('storyfeed.grouping.children_limit', 25);
    }

    protected function filteredActivities(Carbon $now): ActivityBuilder
    {
        return $this->activityModel()->newQuery()
            ->published($now)
            ->when($this->unresolvable, fn (ActivityBuilder $q) => $q->whereRaw('1 = 0'))
            ->when($this->actor, fn (ActivityBuilder $q, Model $m) => $q->actor($m))
            ->when($this->object, fn (ActivityBuilder $q, Model $m) => $q->object($m))
            ->when($this->target, fn (ActivityBuilder $q, Model $m) => $q->target($m))
            ->when($this->context, fn (ActivityBuilder $q, Model $m) => $q->context($m))
            ->when($this->involving, fn (ActivityBuilder $q, Model $m) => $q->involving($m))
            ->when($this->verb, fn (ActivityBuilder $q, string $verb) => $q->verb($verb));
    }

    /**
     * Cursor internals are NOT contract (docs/payload.md) — they encode the
     * position in the merged item stream, not a row offset.
     *
     * @return array{latest: string, rank: int, axis: string|null, hash: string|null, id: int|string|null}|null
     */
    protected function cursorState(): ?array
    {
        $cursor = $this->decodedCursor();

        if ($cursor === null) {
            return null;
        }

        $parameters = $cursor->toArray();

        if (! isset($parameters['latest'], $parameters['rank'])) {
            return null;
        }

        return [
            'latest' => (string) $parameters['latest'],
            'rank' => (int) $parameters['rank'],
            'axis' => $parameters['axis'] ?? null,
            'hash' => $parameters['hash'] ?? null,
            'id' => $parameters['id'] ?? null,
        ];
    }

    protected function encodeCursor(?FeedCandidate $candidate): ?string
    {
        if ($candidate === null) {
            return null;
        }

        return (new Cursor([
            'latest' => $candidate->latest,
            'rank' => $this->rank($candidate),
            'axis' => $candidate->axis,
            'hash' => $candidate->hash,
            'id' => $candidate->activity?->getKey(),
        ]))->encode();
    }

    /**
     * Timestamps arrive as driver strings (aggregates) or Carbon instances
     * (models); both must compare identically in the merge and round-trip
     * through the cursor into a SQL bind.
     */
    protected function normalizeTimestamp(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return ($value instanceof Carbon ? $value : Carbon::parse((string) $value))
            ->format('Y-m-d H:i:s');
    }

    protected function decodedCursor(): ?Cursor
    {
        return $this->cursor === null ? null : Cursor::fromEncoded($this->cursor);
    }

    protected function activityModel(): Activity
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return new $model;
    }

    protected function groupingModel(): Grouping
    {
        $model = config('storyfeed.models.grouping', Grouping::class);

        return new $model;
    }
}
