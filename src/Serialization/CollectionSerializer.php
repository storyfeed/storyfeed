<?php

namespace Storyfeed\Serialization;

use Illuminate\Pagination\Cursor;
use Storyfeed\Models\Activity;

/**
 * Serializes the feed as an AS2.0 OrderedCollection / OrderedCollectionPage.
 *
 * Items are individual activities, strictly reverse-chronological — AS2.0
 * has no grouping concept, so curated grouping never appears here. (The
 * `sf:group` annotation the design once reserved is deliberately not
 * emitted: group identity became view-dependent with ->curated(), so an
 * activity has no single group id to annotate.)
 *
 * `totalItems` is omitted by design — a COUNT over a large feed table on
 * every request is a footgun. The `next` link carries the same opaque
 * cursor mechanism as the app payload.
 */
class CollectionSerializer
{
    public function __construct(
        protected ActivitySerializer $activities,
    ) {}

    /**
     * The feed root (no cursor) or a page (cursor).
     *
     * @return array<string, mixed>
     */
    public function feed(?string $cursor = null, int $limit = 30): array
    {
        $model = config('storyfeed.models.activity', Activity::class);

        $paginator = $model::query()
            ->published()
            ->with(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext'])
            ->orderBy('published_at', 'desc')
            ->orderBy('id', 'desc')
            ->cursorPaginate(
                perPage: $limit,
                cursor: $cursor === null ? null : Cursor::fromEncoded($cursor),
            );

        $items = collect($paginator->items())
            ->map(fn (Activity $activity) => $this->activities->activity($activity, root: false))
            ->all();

        $next = $paginator->nextCursor()?->encode();

        return array_filter([
            '@context' => ActivitySerializer::CONTEXT,
            'id' => $cursor === null ? $this->feedIri() : $this->feedIri($cursor),
            'type' => $cursor === null ? 'OrderedCollection' : 'OrderedCollectionPage',
            'partOf' => $cursor === null ? null : $this->feedIri(),
            'orderedItems' => $items,
            'next' => $next === null ? null : $this->feedIri($next),
        ], fn ($value) => $value !== null);
    }

    protected function feedIri(?string $cursor = null): string
    {
        $prefix = trim((string) config('storyfeed.routes.prefix', 'storyfeed'), '/');

        $url = url("{$prefix}/feed");

        return $cursor === null ? $url : "{$url}?cursor={$cursor}";
    }
}
