<?php

namespace Storyfeed\Payload;

use Illuminate\Support\Collection;
use Storyfeed\Support\FeedItem;

/**
 * One page of the feed on its way out: the nodes, the cursor that follows
 * them and the sync token they were read under. `get()` hands back its
 * nodes as a collection; the paginators carry all three.
 *
 * The cursor is opaque to consumers; its internals may change freely.
 *
 * @internal
 */
final class FeedPage
{
    /** @var array<int, array<string, mixed>>|null */
    protected ?array $presentedItems = null;

    /** @var array<string, array{link: array<string, mixed>|null, media: array<string, mixed>}> */
    protected array $mediaMap = [];

    /**
     * @param  Collection<int, GroupSlice>  $slices  page items, already ordered
     */
    public function __construct(
        protected Collection $slices,
        protected ?string $nextCursor,
        protected NodePresenter $presenter,
        protected ?string $syncToken = null,
        protected ?string $prevCursor = null,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function items(): array
    {
        if ($this->presentedItems !== null) {
            return $this->presentedItems;
        }

        // One identity map per build, seeded with everything this page holds,
        // so a resolver's first $context->model() loads its whole class at
        // once. Each page builds once; distinct pages never share models.
        $presenter = $this->presenter->forPage($this->slices);

        $this->presentedItems = $this->slices
            ->map(fn (GroupSlice $slice) => $presenter->node($slice))
            ->values()
            ->all();
        $this->mediaMap = $presenter->mediaMap();

        return $this->presentedItems;
    }

    /**
     * The page's media map (#242): each distinct `{link, media}` pair its
     * entities carry, keyed by the `media_ref` they name it by.
     *
     * @return array<string, array{link: array<string, mixed>|null, media: array<string, mixed>}>
     */
    public function media(): array
    {
        $this->items();

        return $this->mediaMap;
    }

    /**
     * The page's items as they travel in a paginator: with the inline `link`
     * and `media` copies, or, when `storyfeed.payload.inline_media` is off,
     * without them on every entity whose `media_ref` names them in the map.
     *
     * @return array<int, array<string, mixed>>
     */
    public function transported(): array
    {
        $items = $this->items();

        return config('storyfeed.payload.inline_media', true) === false
            ? array_map(self::strip(...), $items)
            : $items;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function strip(array $value): array
    {
        if (is_string($value['media_ref'] ?? null) && array_key_exists('media', $value) && array_key_exists('type', $value)) {
            unset($value['link'], $value['media']);
        }

        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::strip($child);
            }
        }

        return $value;
    }

    /**
     * The page's items as FeedItem readers.
     *
     * @return Collection<int, FeedItem>
     */
    public function collect(): Collection
    {
        return collect($this->items())->map(FeedItem::of(...));
    }

    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    /** The cursor that pages backward from the first item, or null on a feed's first page. */
    public function prevCursor(): ?string
    {
        return $this->prevCursor;
    }

    /**
     * Opaque, cursor-grained: store it; when a later page's token differs,
     * settled history was rewritten — drop ALL accumulated nodes and
     * refetch. Null until the first rewrite ever.
     */
    public function syncToken(): ?string
    {
        return $this->syncToken;
    }
}
