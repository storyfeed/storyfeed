<?php

namespace Storyfeed;

use Closure;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Paginator;
use Storyfeed\Payload\FeedPage;
use Storyfeed\Support\FeedCursor;
use Storyfeed\Support\FeedItem;

/**
 * Laravel pagination over a forward-only feed.
 *
 * @extends CursorPaginator<int, FeedItem>
 */
class FeedPaginator extends CursorPaginator
{
    /** @var array<string, mixed> */
    protected array $payload;

    protected ?FeedCursor $nextFeedCursor;

    // Keep feed resolution separate from Eloquent's parameter-decoding resolver.
    protected static ?Closure $feedCursorResolver = null;

    public function __construct(FeedPage $page, int $perPage, ?Cursor $cursor = null, string $cursorName = 'cursor')
    {
        $payload = $page->toArray();
        $this->payload = $payload;
        $this->nextFeedCursor = FeedCursor::fromEncoded($page->nextCursor());

        parent::__construct(collect($payload['items'])->map(FeedItem::of(...)), $perPage, $cursor, [
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $pagination = parent::toArray();

        return array_merge($this->payload, ['items' => $pagination['data']], $pagination);
    }
}
