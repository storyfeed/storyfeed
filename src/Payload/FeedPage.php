<?php

namespace Storyfeed\Payload;

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Collection;
use JsonSerializable;

/**
 * One page of the feed, emitting the Payload v1 envelope
 * ({payload_version, items, next_cursor}) — see docs/payload.md.
 *
 * The cursor is opaque to consumers; its internals may change freely.
 */
final class FeedPage implements Arrayable, JsonSerializable, Responsable
{
    /**
     * @param  Collection<int, GroupSlice>  $slices  page items, already ordered
     */
    public function __construct(
        protected Collection $slices,
        protected ?string $nextCursor,
        protected NodePresenter $presenter,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        return $this->slices
            ->map(fn (GroupSlice $slice) => $this->presenter->node($slice))
            ->values()
            ->all();
    }

    public function nextCursor(): ?string
    {
        return $this->nextCursor;
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
