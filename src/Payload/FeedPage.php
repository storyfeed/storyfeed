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
        if ($this->presentedItems !== null) {
            return $this->presentedItems;
        }

        // One identity map per build, seeded with everything this page holds,
        // so a resolver's first $context->model() loads its whole class at
        // once. Each page builds once; distinct pages never share models.
        $presenter = $this->presenter->forPage($this->slices);

        return $this->presentedItems = $this->slices
            ->map(fn (GroupSlice $slice) => $presenter->node($slice))
            ->values()
            ->all();
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
