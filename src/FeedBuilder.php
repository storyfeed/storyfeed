<?php

namespace Storyfeed;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Pagination\Cursor;
use Storyfeed\Grouping\NullStrategy;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\Grouping;
use Storyfeed\Payload\FeedPage;
use Storyfeed\Payload\NodePresenter;

/**
 * Fluent reader for feeds.
 *
 *   Storyfeed::feed()->limit(30)->get();
 *   Storyfeed::feed()->context($project)->get();
 *   Storyfeed::feed()->actor($user)->limit(15)->get();
 *
 * INTERIM READ STRATEGY (explicitly unsettled — docs/grouping.md): groups
 * are selected via a top-N subquery over the `repeat` axis hashes, then the
 * page over-fetches raw rows to fill nested groups. The curated,
 * materialized read model is slated to replace this behind the same opaque
 * cursor.
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

    public function actor(Model $model): static
    {
        $this->actor = $model;

        return $this;
    }

    public function object(Model $model): static
    {
        $this->object = $model;

        return $this;
    }

    public function target(Model $model): static
    {
        $this->target = $model;

        return $this;
    }

    public function context(Model $model): static
    {
        $this->context = $model;

        return $this;
    }

    /**
     * Activities involving the model in any role.
     */
    public function for(Model $model): static
    {
        $this->involving = $model;

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
        $paginator = $this->grouped()
            ? $this->paginateGrouped()
            : $this->paginateFlat();

        return new FeedPage($paginator, app(NodePresenter::class));
    }

    protected function grouped(): bool
    {
        return ! is_a(config('storyfeed.grouping.strategy'), NullStrategy::class, true);
    }

    protected function paginateGrouped()
    {
        $activities = $this->activityModel()->getTable();
        $groupings = $this->groupingModel()->getTable();

        $filtered = $this->filteredActivities()
            ->select(["{$activities}.id as fa_id", "{$activities}.published_at as fa_published"]);

        $topHashes = $this->groupingModel()->newQuery()
            ->where('bucket', 'repeat')
            ->joinSub($filtered, 'fa', fn (JoinClause $join) => $join->on('fa.fa_id', '=', "{$groupings}.activity_id"))
            ->groupBy('hash')
            ->orderByRaw('MAX(fa.fa_published) DESC')
            ->limit($this->limit)
            ->pluck('hash');

        // LEFT JOIN so activities without grouping rows (legacy/imported)
        // still surface — as solo nodes — instead of silently vanishing.
        // The trickle backfills their hashes so they converge into groups.
        return $this->filteredActivities()
            ->leftJoin($groupings, function (JoinClause $join) use ($activities, $groupings) {
                $join->on("{$groupings}.activity_id", '=', "{$activities}.id")
                    ->where("{$groupings}.bucket", 'repeat');
            })
            ->where(function ($query) use ($groupings, $topHashes) {
                $query->whereIn("{$groupings}.hash", $topHashes)
                    ->orWhereNull("{$groupings}.hash");
            })
            ->select(["{$activities}.*", "{$groupings}.hash as group_hash"])
            ->with(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext'])
            ->orderBy("{$activities}.published_at", 'desc')
            ->orderBy("{$activities}.id", 'desc')
            ->cursorPaginate(
                perPage: $this->limit * 10, // over-fetch to fill nested groups (interim)
                cursor: $this->decodedCursor(),
            );
    }

    protected function paginateFlat()
    {
        $activities = $this->activityModel()->getTable();

        return $this->filteredActivities()
            ->with(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext'])
            ->orderBy("{$activities}.published_at", 'desc')
            ->orderBy("{$activities}.id", 'desc')
            ->cursorPaginate(perPage: $this->limit, cursor: $this->decodedCursor());
    }

    protected function filteredActivities(): ActivityBuilder
    {
        return $this->activityModel()->newQuery()
            ->published()
            ->when($this->actor, fn (ActivityBuilder $q, Model $m) => $q->actor($m))
            ->when($this->object, fn (ActivityBuilder $q, Model $m) => $q->object($m))
            ->when($this->target, fn (ActivityBuilder $q, Model $m) => $q->target($m))
            ->when($this->context, fn (ActivityBuilder $q, Model $m) => $q->context($m))
            ->when($this->involving, fn (ActivityBuilder $q, Model $m) => $q->involving($m))
            ->when($this->verb, fn (ActivityBuilder $q, string $verb) => $q->verb($verb));
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
