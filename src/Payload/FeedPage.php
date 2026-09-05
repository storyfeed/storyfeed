<?php

namespace Storyfeed\Payload;

use ArrayAccess;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Support\Collection;
use JsonSerializable;
use LogicException;

/**
 * One page of the feed, emitting the Payload v1 envelope
 * ({payload_version, items, next_cursor}) — see docs/payload.md.
 *
 * The cursor is opaque to consumers; its internals may change freely.
 *
 * Read-only ArrayAccess mirrors the JSON envelope, so the first instinct
 * ($page['items']) works the same in PHP as it does client-side.
 *
 * @implements ArrayAccess<string, mixed>
 */
final class FeedPage implements Arrayable, ArrayAccess, JsonSerializable, Responsable
{
    /**
     * @param  Collection<int, GroupSlice>  $slices  page items, already ordered
     */
    public function __construct(
        protected Collection $slices,
        protected ?string $nextCursor,
        protected NodePresenter $presenter,
        protected ?string $syncToken = null,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        // One identity map per build, seeded with everything this page holds,
        // so a resolver's first $context->model() loads its whole class at
        // once. Rebuilt on every call: two calls are two builds, and neither
        // may see the other's models.
        $presenter = $this->presenter->forPage($this->slices);

        return $this->slices
            ->map(fn (GroupSlice $slice) => $presenter->node($slice))
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
            // Opaque, cursor-grained: store it; when a later page's token
            // differs, settled history was rewritten — drop ALL accumulated
            // nodes and refetch. Null until the first rewrite ever.
            'sync_token' => $this->syncToken,
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

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->toArray());
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->toArray()[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('FeedPage is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('FeedPage is read-only.');
    }
}
