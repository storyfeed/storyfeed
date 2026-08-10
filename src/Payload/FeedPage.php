<?php

namespace Storyfeed\Payload;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Support\Collection;
use JsonSerializable;
use Storyfeed\Models\Activity;

/**
 * One page of the feed, emitting the Payload v1 envelope
 * ({payload_version, items, next_cursor}) — see docs/payload.md.
 *
 * The cursor is opaque to consumers; its internals may change freely.
 */
final class FeedPage implements Arrayable, JsonSerializable, Responsable
{
    public function __construct(
        protected CursorPaginator $paginator,
        protected NodePresenter $presenter,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        /** @var Collection<int, Activity> $rows */
        $rows = Collection::make($this->paginator->items());

        return $rows
            ->groupBy(fn (Activity $activity) => $activity->group_hash ?? 'solo:'.$activity->getKey())
            ->map(fn (Collection $members) => $this->presenter->node($members))
            ->values()
            ->all();
    }

    public function nextCursor(): ?string
    {
        return $this->paginator->nextCursor()?->encode();
    }

    public function toArray(): array
    {
        return [
            'payload_version' => 1,
            'items' => $this->items(),
            'next_cursor' => $this->nextCursor(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function toResponse($request)
    {
        return response()->json($this->toArray());
    }
}
