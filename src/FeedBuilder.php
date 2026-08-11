<?php

namespace Storyfeed;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Storyfeed\Grouping\NullStrategy;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Party;
use Storyfeed\Payload\FeedPage;
use Storyfeed\Payload\GroupSlice;
use Storyfeed\Payload\NodePresenter;

/**
 * Fluent reader for feeds.
 *
 *   Storyfeed::feed()->limit(30)->get();
 *   Storyfeed::feed()->context($project)->get();
 *   Storyfeed::feed()->actor($user)->limit(15)->get();
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

        return $this->grouped()
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

    protected function grouped(): bool
    {
        return ! is_a(config('storyfeed.grouping.strategy'), NullStrategy::class, true);
    }

    protected function groupedPage(Carbon $now): FeedPage
    {
        $candidates = $this->selectItems($now);

        $more = $candidates->count() > $this->limit;
        $candidates = $candidates->take($this->limit)->values();

        $next = $more ? $this->encodeCursor($candidates->last()) : null;

        $hashes = $candidates
            ->map(fn (FeedCandidate $candidate) => $candidate->hash)
            ->filter()
            ->values()
            ->all();

        $members = $this->fetchMembers($now, $hashes);

        $slices = $candidates->map(function (FeedCandidate $candidate) use ($members): GroupSlice {
            if ($candidate->activity !== null) {
                return GroupSlice::solo($candidate->activity);
            }

            return GroupSlice::group(
                'repeat',
                (string) $candidate->hash,
                $candidate->count,
                $members->get((string) $candidate->hash) ?? $this->activityModel()->newCollection(),
            );
        });

        return new FeedPage($slices, $next, app(NodePresenter::class));
    }

    protected function flatPage(Carbon $now): FeedPage
    {
        $activities = $this->activityModel()->getTable();

        $paginator = $this->filteredActivities($now)
            ->with(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext'])
            ->orderBy("{$activities}.published_at", 'desc')
            ->orderBy("{$activities}.id", 'desc')
            ->cursorPaginate(perPage: $this->limit, cursor: $this->decodedCursor());

        $slices = Collection::make($paginator->items())
            ->map(fn (Activity $activity) => GroupSlice::solo($activity));

        return new FeedPage($slices, $paginator->nextCursor()?->encode(), app(NodePresenter::class));
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
     */
    protected function compareTiebreak(FeedCandidate $a, FeedCandidate $b): int
    {
        if ($a->hash !== null && $b->hash !== null) {
            return strcmp($a->hash, $b->hash);
        }

        if ($a->activity !== null && $b->activity !== null) {
            return $a->activity->getKey() <=> $b->activity->getKey();
        }

        return 0;
    }

    /**
     * @param  array{latest: string, rank: int, hash: string|null, id: int|string|null}|null  $cursor
     * @return Collection<int, FeedCandidate>
     */
    protected function groupStream(Carbon $now, ?array $cursor): Collection
    {
        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $grammar = $this->groupingModel()->getConnection()->getQueryGrammar();
        $hashColumn = $grammar->wrapTable($groupings).'.'.$grammar->wrap('hash');
        $latest = 'max(fa.fa_published)';

        $filtered = $this->filteredActivities($now)
            ->select(["{$activities}.id as fa_id", "{$activities}.published_at as fa_published"]);

        $query = $this->groupingModel()->newQuery()
            ->where("{$groupings}.bucket", 'repeat')
            ->joinSub($filtered, 'fa', fn (JoinClause $join) => $join->on('fa.fa_id', '=', "{$groupings}.activity_id"))
            ->groupBy("{$groupings}.hash")
            ->select("{$groupings}.hash")
            ->selectRaw("{$latest} as latest")
            ->selectRaw('count(*) as members')
            ->orderByRaw("{$latest} desc")
            ->orderBy("{$groupings}.hash")
            ->limit($this->limit + 1);

        // Groups rank before solos at an identical timestamp, so a solo
        // cursor has already consumed every group in that tie.
        if ($cursor !== null && $cursor['rank'] === self::RANK_GROUP) {
            $query->havingRaw(
                "({$latest} < ? or ({$latest} = ? and {$hashColumn} > ?))",
                [$cursor['latest'], $cursor['latest'], $cursor['hash']],
            );
        } elseif ($cursor !== null) {
            $query->havingRaw("{$latest} < ?", [$cursor['latest']]);
        }

        return $query->get()->map(fn (Grouping $row) => FeedCandidate::group(
            $this->normalizeTimestamp($row->getAttribute('latest')),
            (string) $row->getAttribute('hash'),
            (int) $row->getAttribute('members'),
        ));
    }

    /**
     * Activities carrying no `repeat` grouping row (legacy, imported, or
     * awaiting the trickle). Their presence here is what keeps graceful
     * degradation true: the read path never hides an activity.
     *
     * @param  array{latest: string, rank: int, hash: string|null, id: int|string|null}|null  $cursor
     * @return Collection<int, FeedCandidate>
     */
    protected function soloStream(Carbon $now, ?array $cursor): Collection
    {
        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $query = $this->filteredActivities($now)
            ->whereNotExists(fn (QueryBuilder $sub) => $sub
                ->from($groupings)
                ->whereColumn("{$groupings}.activity_id", "{$activities}.id")
                ->where("{$groupings}.bucket", 'repeat')
                ->selectRaw('1'))
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
     * @param  array<int, string>  $hashes
     * @return Collection<array-key, EloquentCollection<int, Activity>>
     */
    protected function fetchMembers(Carbon $now, array $hashes): Collection
    {
        if ($hashes === []) {
            return Collection::make();
        }

        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $grammar = $this->activityModel()->getConnection()->getQueryGrammar();
        $partition = sprintf(
            'row_number() over (partition by %s order by %s desc, %s desc) as member_rank',
            $grammar->wrapTable($groupings).'.'.$grammar->wrap('hash'),
            $grammar->wrapTable($activities).'.'.$grammar->wrap('published_at'),
            $grammar->wrapTable($activities).'.'.$grammar->wrap('id'),
        );

        $ranked = $this->filteredActivities($now)
            ->join($groupings, fn (JoinClause $join) => $join
                ->on("{$groupings}.activity_id", '=', "{$activities}.id")
                ->where("{$groupings}.bucket", 'repeat'))
            ->whereIn("{$groupings}.hash", $hashes)
            ->select(["{$activities}.*", "{$groupings}.hash as group_hash"])
            ->selectRaw($partition);

        $rows = $this->activityModel()->getConnection()->query()
            ->fromSub($ranked, 'ranked')
            ->where('member_rank', '<=', $this->childrenLimit())
            ->orderBy('published_at', 'desc')
            ->orderBy('id', 'desc')
            ->get();

        $members = $this->activityModel()->newQuery()->hydrate($rows->all());

        $members->load(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext']);

        return $members->groupBy(fn (Activity $activity) => (string) $activity->group_hash);
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
     * @return array{latest: string, rank: int, hash: string|null, id: int|string|null}|null
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
