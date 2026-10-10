<?php

use Illuminate\Contracts\Pagination\CursorPaginator as CursorPaginatorContract;
use Illuminate\Http\Request;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Blade;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedPaginator;
use Storyfeed\Payload\FeedPage;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Support\FeedCursor;
use Storyfeed\Support\FeedItem;

beforeEach(function () {
    foreach (range(1, 5) as $i) {
        Storyfeed::activity('visit')->publishedAt(now()->subMinutes($i))->publish();
    }
});

it('resolves request cursors and follows every page without duplicates', function (string $cursorName) {
    $builder = Storyfeed::feed()->log();
    $all = $builder->get()->toArray();
    $seen = [];
    $url = 'https://example.test/feed';

    do {
        $this->app->instance('request', Request::create($url));
        $page = $builder->cursorPaginate(2, $cursorName);
        expect($page)->toBeInstanceOf(CursorPaginatorContract::class)
            ->and($page->perPage())->toBe(2)
            ->and($page->previousCursor())->toBeNull()
            ->and($page->previousPageUrl())->toBeNull();
        array_push($seen, ...$page->toArray()['data']);
        $url = $page->nextPageUrl();
        if ($url !== null) {
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            expect($query[$cursorName])->toBe($page->nextCursor()->encode());
        }
    } while ($url !== null);

    expect($seen)->toBe($all)
        ->and($page->hasMorePages())->toBeFalse()
        ->and($page->hasPages())->toBeFalse()
        ->and($page->onLastPage())->toBeTrue();
})->with(['cursor', 'feed_cursor']);

it('preserves query parameters and fragments without replacing the next cursor', function () {
    $this->app->instance('request', Request::create('https://example.test/feed?filter=mine&feed_cursor=old'));
    $page = Storyfeed::feed()->log()->cursorPaginate(2, 'feed_cursor')
        ->withQueryString()->appends(['sort' => 'recent', 'feed_cursor' => 'wrong'])
        ->appends('view', 'compact')->fragment('activity');

    parse_str(parse_url($page->nextPageUrl(), PHP_URL_QUERY), $query);
    expect($query)->toBe([
        'filter' => 'mine', 'sort' => 'recent', 'view' => 'compact',
        'feed_cursor' => $page->nextCursor()->encode(),
    ])->and($page->path())->toBe('https://example.test/feed')
        ->and(parse_url($page->nextPageUrl(), PHP_URL_FRAGMENT))->toBe('activity')
        ->and($page->fragment())->toBe('activity');

    $page->withPath('https://example.test/archive?lang=en');
    expect($page->nextPageUrl())->toStartWith('https://example.test/archive?lang=en&');
});

it('renders Laravel pagination views and honours the application default view', function () {
    $page = Storyfeed::feed()->log()->cursorPaginate(2);
    expect($page->links()->name())->toBe(Paginator::$defaultSimpleView);
    $html = Blade::render('{{ $page->links() }}', ['page' => $page]);
    expect($html)->toContain('rel="next"')->not->toContain('rel="prev"');

    $this->app->instance('request', Request::create($page->nextPageUrl()));
    $second = Storyfeed::feed()->log()->cursorPaginate(2);
    expect(Blade::render('{{ $page->links() }}', ['page' => $second]))
        ->toContain('rel="next"')->not->toContain('rel="prev"');

    $original = Paginator::$defaultSimpleView;
    try {
        Paginator::defaultSimpleView('pagination::simple-bootstrap-5');
        expect($page->links()->name())->toBe('pagination::simple-bootstrap-5')
            ->and((string) $page->links())->toContain('rel="next"')
            ->and($page->links('pagination::simple-tailwind')->name())->toBe('pagination::simple-tailwind');
    } finally {
        Paginator::defaultSimpleView($original);
    }
});

it('is Laravel\'s cursor paginator JSON plus the two feed keys, and iterates FeedItems', function () {
    $builder = Storyfeed::feed()->log()->limit(2);
    $plain = $builder->get();
    $page = $builder->cursorPaginate();
    $payload = $page->toArray();

    expect(array_keys($payload))->toBe([
        'data', 'path', 'per_page', 'next_cursor', 'next_page_url', 'prev_cursor', 'prev_page_url',
        'payload_version', 'sync_token',
    ])
        ->and($payload['data'])->toBe($plain->toArray())
        ->and($payload['payload_version'])->toBe(1)
        ->and($payload['sync_token'])->toBe($page->syncToken())
        ->and($payload['next_cursor'])->toBe($page->nextCursor()->encode())
        ->and($payload['path'])->toBe($page->path())
        ->and($payload['per_page'])->toBe(2)
        ->and($payload['next_page_url'])->toBe($page->nextPageUrl())
        ->and($payload['prev_cursor'])->toBeNull()
        ->and($payload['prev_page_url'])->toBeNull()
        ->and(json_decode($page->toJson(), true))->toBe($payload)
        ->and(json_decode(json_encode($page), true))->toBe($payload)
        ->and(iterator_to_array($page)[0])->toBeInstanceOf(FeedItem::class)
        ->and($page->items()[0])->toBeInstanceOf(FeedItem::class)
        ->and($page[0]->toArray())->toBe($plain->toArray()[0])
        ->and($page)->toHaveCount(2);
});

it('keeps get independent of request pagination and does not mutate the builder', function () {
    $builder = Storyfeed::feed()->log();
    $first = $builder->limit(1)->get();
    $next = $builder->cursorPaginate()->nextCursor()->encode();
    $this->app->instance('request', Request::create('https://example.test/feed?cursor='.urlencode($next)));
    $page = $builder->cursorPaginate(2);

    expect($page->cursor()->encode())->toBe($next)
        ->and($page->toArray()['data'][0]['id'])->not->toBe($first[0]['id'])
        ->and($builder->get())->toBeInstanceOf(Collection::class)
        ->and($builder->get()->toArray())->toBe($first->toArray())
        ->and(array_is_list($first->toArray()))->toBeTrue();
});

it('takes a cursor as an argument, or from cursor(), before the request\'s', function () {
    $ids = Storyfeed::feed()->log()->get()->pluck('id')->all();
    $builder = Storyfeed::feed()->log()->limit(2);
    $next = $builder->cursorPaginate()->nextCursor();
    $this->app->instance('request', Request::create('https://example.test/feed?cursor=ignored'));
    $read = fn (FeedPaginator $page) => $page->getCollection()->pluck('id')->all();

    expect($read($builder->cursorPaginate(cursor: $next)))->toBe(array_slice($ids, 2, 2))
        ->and($read($builder->cursorPaginate(cursor: $next->encode())))->toBe(array_slice($ids, 2, 2))
        ->and($read((clone $builder)->cursor($next->encode())->cursorPaginate()))->toBe(array_slice($ids, 2, 2));
});

it('defaults to thirty items and rejects nonpositive page sizes', function () {
    expect(Storyfeed::feed()->cursorPaginate()->perPage())->toBe(30);
    expect(fn () => Storyfeed::feed()->cursorPaginate(0))->toThrow(InvalidArgumentException::class);
    expect(fn () => Storyfeed::feed()->limit(-1)->cursorPaginate())->toThrow(InvalidArgumentException::class);
});

it('transports opaque cursors unchanged without exposing decoded parameters', function () {
    $token = 'opaque+/=token-with-no-laravel-encoding';
    $cursor = FeedCursor::fromEncoded($token);
    expect($cursor)->toBeInstanceOf(Cursor::class)
        ->and($cursor->encode())->toBe($token)
        ->and($cursor->toArray())->toBe(['_pointsToNextItems' => true])
        ->and($cursor->pointsToPreviousItems())->toBeFalse()
        ->and(FeedCursor::fromEncoded(null))->toBeNull()
        ->and(FeedCursor::fromEncoded(['cursor']))->toBeNull();

    $this->app->instance('request', Request::create('https://example.test/feed?cursor='.urlencode($token)));
    expect(FeedPaginator::resolveCurrentCursor()->encode())->toBe($token)
        ->and(CursorPaginator::resolveCurrentCursor())->toBeNull();

    $empty = new FeedPage(collect(), $token, app(NodePresenter::class), 'opaque-sync-token');
    $page = new FeedPaginator($empty, 2);
    expect($page->isEmpty())->toBeTrue()
        ->and($page->hasMorePages())->toBeTrue()
        ->and($page->nextCursor()->encode())->toBe($token)
        ->and($page->toArray()['sync_token'])->toBe('opaque-sync-token')
        ->and($page->nextPageUrl())->toContain(rawurlencode($token));
});

it('returns an empty final page without pagination links', function () {
    $page = Storyfeed::feed()->verb('missing')->cursorPaginate(2);
    expect($page->isEmpty())->toBeTrue()
        ->and($page->isNotEmpty())->toBeFalse()
        ->and($page->nextCursor())->toBeNull()
        ->and($page->hasPages())->toBeFalse()
        ->and(trim((string) $page->links()))->toBe('');
});

it('paginates each feed mode with the same items as get', function (string $mode) {
    $builder = Storyfeed::feed()->{$mode}()->limit(2);
    $plain = $builder->get();
    $page = $builder->cursorPaginate(2);
    expect($page->toArray()['data'])->toBe($plain->toArray());
})->with(['log', 'live']);

it('supports a feed resolver without changing Eloquent cursor resolution', function () {
    $first = Storyfeed::feed()->log()->limit(1)->cursorPaginate();
    $this->app->instance('request', Request::create('https://example.test/feed'));
    $resolver = new ReflectionProperty(FeedPaginator::class, 'feedCursorResolver');
    $original = $resolver->getValue();

    try {
        FeedPaginator::currentCursorResolver(function (string $name) use ($first) {
            expect($name)->toBe('feed_cursor');

            return $first->nextCursor();
        });

        $page = Storyfeed::feed()->log()->cursorPaginate(2, 'feed_cursor');
        expect($page->cursor()->encode())->toBe($first->nextCursor()->encode())
            ->and($page->toArray()['data'][0]['id'])->not->toBe($first->items()[0]['id'])
            ->and(CursorPaginator::resolveCurrentCursor())->toBeNull();
    } finally {
        $resolver->setValue(null, $original);
    }
});
