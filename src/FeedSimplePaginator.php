<?php

namespace Storyfeed;

use Illuminate\Pagination\Paginator;
use Storyfeed\Concerns\CarriesFeedKeys;
use Storyfeed\Payload\FeedPage;
use Storyfeed\Support\FeedItem;

/**
 * Laravel simple pagination over the feed: numbered pages with no total,
 * the nodes in `data`, plus `payload_version` and `sync_token`.
 *
 * @extends Paginator<int, FeedItem>
 */
class FeedSimplePaginator extends Paginator
{
    use CarriesFeedKeys;

    /** @internal */
    public function __construct(FeedPage $page, int $perPage, int $currentPage, string $pageName = 'page')
    {
        $this->syncToken = $page->syncToken();

        parent::__construct($page->collect(), $perPage, $currentPage, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);

        // The read knows whether more follows; the page holds no extra node.
        $this->hasMorePagesWhen($page->nextCursor() !== null);
    }
}
