<?php

namespace Storyfeed;

use Closure;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Paginator;
use Storyfeed\Concerns\CarriesFeedKeys;
use Storyfeed\Payload\FeedPage;
use Storyfeed\Support\FeedCursor;
use Storyfeed\Support\FeedItem;

/**
 * Laravel cursor pagination over a forward-only feed: the nodes in `data`,
 * plus `payload_version` and `sync_token`.
 *
 * @extends CursorPaginator<int, FeedItem>
 */
class FeedPaginator extends CursorPaginator
{
    use CarriesFeedKeys;

    protected ?FeedCursor $nextFeedCursor;

    // Keep feed resolution separate from Eloquent's parameter-decoding resolver.
    protected static ?Closure $feedCursorResolver = null;

    /** @internal */
    public function __construct(FeedPage $page, int $perPage, ?Cursor $cursor = null, string $cursorName = 'cursor')
    {
        $this->nextFeedCursor = FeedCursor::fromEncoded($page->nextCursor());
        $this->syncToken = $page->syncToken();

        parent::__construct($page->collect(), $perPage, $cursor, [
            'path' => Paginator::resolveCurrentPath(),
            'cursorName' => $cursorName,
        ]);

        $this->hasMore = $this->nextFeedCursor !== null;
    }

    public static function resolveCurrentCursor($cursorName = 'cursor', $default = null)
    {
        if (static::$feedCursorResolver !== null) {
            return (static::$feedCursorResolver)($cursorName);
        }

        return FeedCursor::fromEncoded(request()->input($cursorName)) ?? $default;
    }

    public static function currentCursorResolver(Closure $resolver): void
    {
        static::$feedCursorResolver = $resolver;
    }

    public function nextCursor(): ?Cursor
    {
        return $this->nextFeedCursor;
    }

    /** Null until backward paging exists (#96). */
    public function previousCursor(): ?Cursor
    {
        return null;
    }

    public function hasMorePages(): bool
    {
        return $this->hasMore;
    }

    public function onFirstPage(): bool
    {
        // Laravel's simple views use this to disable the previous-page link.
        return $this->previousCursor() === null;
    }
}
