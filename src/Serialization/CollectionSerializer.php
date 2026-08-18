<?php

namespace Storyfeed\Serialization;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Storyfeed\Models\Activity;

/**
 * Serializes a page of activities as an AS2.0 OrderedCollection /
 * OrderedCollectionPage.
 *
 * Items are individual activities, strictly reverse-chronological — AS2.0
 * has no grouping concept, so curated grouping never appears here. (The
 * `sf:group` annotation the design once reserved is deliberately not
 * emitted: group identity became view-dependent with ->summary(), so an
 * activity has no single group id to annotate.)
 *
 * `totalItems` is omitted by design — a COUNT over a large feed table on
 * every request is a footgun. The `next` link carries the same opaque
 * cursor mechanism as the app payload.
 *
 * IT BUILDS NO QUERY AND OWNS NO IRI. Both are the caller's, and that is the
 * point: an earlier version of this class ran its own
 * `Activity::query()->published()->cursorPaginate()`, which is every published
 * activity in the system with no scope and no verb allowlist. It backed an HTTP
 * endpoint that has since been removed (see the CHANGELOG for v0.8.0-alpha.2).
 * When the collection endpoint returns it will be backed by a named feed, and
 * the feed will supply both the activities and the IRI they live at. Serializing
 * a collection is this class's job; deciding WHICH activities is not, and the
 * shape of this signature is what keeps that true.
 */
class CollectionSerializer
{
    public function __construct(
        protected ActivitySerializer $activities,
    ) {}

    /**
     * The collection root (no cursor) or one page of it (cursor).
     *
     * @param  CursorPaginator<int, Activity>  $page
     * @param  string  $iri  where this collection lives — an absolute URL the
     *                       caller is responsible for being able to serve
     * @param  string|null  $cursor  the cursor this page was reached by, if any
     * @return array<string, mixed>
     */
    public function collection(CursorPaginator $page, string $iri, ?string $cursor = null): array
    {
        $items = collect($page->items())
            ->map(fn (Activity $activity) => $this->activities->activity($activity, root: false))
            ->all();

        $next = $page->nextCursor()?->encode();

        return array_filter([
            '@context' => ActivitySerializer::CONTEXT,
            'id' => $this->at($iri, $cursor),
            'type' => $cursor === null ? 'OrderedCollection' : 'OrderedCollectionPage',
            'partOf' => $cursor === null ? null : $iri,
            'orderedItems' => $items,
            'next' => $next === null ? null : $this->at($iri, $next),
        ], fn ($value) => $value !== null);
    }

    protected function at(string $iri, ?string $cursor): string
    {
        return $cursor === null ? $iri : $iri.'?cursor='.$cursor;
    }
}
