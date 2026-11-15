<?php

namespace Storyfeed;

use ArrayIterator;
use Countable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use Illuminate\Support\Collection;
use IteratorAggregate;
use JsonSerializable;
use Storyfeed\Payload\FeedPage;
use Storyfeed\Support\FeedItem;
use Traversable;

/**
 * One read in the shape of a page, without pagination: what page() returns
 * (#255). The nodes in `data`, plus `payload_version`, `sync_token` and the
 * `entities` and `media` maps, as a paginator carries them, and no links,
 * cursors or page numbers.
 *
 *   Storyfeed::feed()->limit(8)->inlineEntities(false)->page();
 *
 * Iterate it for the FeedItem readers, or return it from a route.
 *
 * @implements Arrayable<string, mixed>
 * @implements IteratorAggregate<int, FeedItem>
 */
final class FeedResult implements Arrayable, Countable, IteratorAggregate, Jsonable, JsonSerializable
{
    /** @var Collection<int, FeedItem> */
    protected Collection $items;

    protected ?string $syncToken;

    /** @var array<string, array{link: array<string, mixed>|null, media: array<string, mixed>}> */
    protected array $mediaMap;

    /** @var array<string, array<string, mixed>> */
    protected array $entityMap;

    /** @var array<string, array{link: array<string, mixed>|null, media: array<string, mixed>}> */
    protected array $transportedMedia;

    /** @var array<string, array<string, mixed>> */
    protected array $transportedEntities;

    /** @internal */
    public function __construct(FeedPage $page)
    {
        $this->items = collect($page->transported())->map(FeedItem::of(...));
        $this->syncToken = $page->syncToken();
        $this->mediaMap = $page->media();
        $this->entityMap = $page->entities();
        $this->transportedMedia = $page->transportedMedia();
        $this->transportedEntities = $page->transportedEntities();
    }

    /**
     * The nodes as they travel: whole entities, or stubs when inline
     * entities are off.
     *
     * @return Collection<int, FeedItem>
     */
    public function items(): Collection
    {
        return $this->items;
    }

    /**
     * Each distinct entity on the page, whole, keyed by the `entity_ref`
     * its entities carry: the full map, whether or not it travels.
     *
     * @return array<string, array<string, mixed>>
     */
    public function entities(): array
    {
        return $this->entityMap;
    }

    /**
     * Each distinct `{link, media}` pair on the page, keyed by the
     * `media_ref` its entities carry: the full map, whether or not it
     * travels.
     *
     * @return array<string, array{link: array<string, mixed>|null, media: array<string, mixed>}>
     */
    public function media(): array
    {
        return $this->mediaMap;
    }

    /**
     * Opaque: when a later read's token differs, settled history was
     * rewritten. Null until the first rewrite ever, and on a source's reads.
     */
    public function syncToken(): ?string
    {
        return $this->syncToken;
    }

    public function count(): int
    {
        return $this->items->count();
    }

    /** @return Traversable<int, FeedItem> */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items->all());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'data' => $this->items->toArray(),
            'payload_version' => 1,
            'sync_token' => $this->syncToken,
            'media' => $this->transportedMedia,
            'entities' => $this->transportedEntities,
        ];
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** @param  int  $options */
    public function toJson($options = 0): string
    {
        return (string) json_encode($this->jsonSerialize(), $options);
    }
}
