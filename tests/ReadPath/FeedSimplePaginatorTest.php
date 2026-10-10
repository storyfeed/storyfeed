<?php

use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Http\Request;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedSimplePaginator;
use Storyfeed\Sources\ArraySource;
use Storyfeed\Sources\Entry;
use Storyfeed\Support\FeedItem;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

beforeEach(function () {
    $sally = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    // Solos and groups interleaved, so live pages cross group nodes;
    // published oldest first, as bursts are.
    foreach (range(9, 1) as $i) {
        $verb = in_array($i, [2, 3, 6, 7], true) ? 'upload' : "visit-{$i}";
        Storyfeed::activity($verb, Delivery::create(['tracking_number' => "TN-{$i}"]))
            ->actor($verb === 'upload' && $i > 5 ? User::create(['name' => "U{$i}", 'email' => "u{$i}@example.com"]) : $sally)
            ->publishedAt(now()->subMinutes(10 * $i))
            ->publish();
    }
});

it('pages by number with the same items cursor paging reads, in every mode', function (string $mode) {
    $all = Storyfeed::feed()->{$mode}()->get()->toArray();
    $seen = [];

    foreach (range(1, 10) as $number) {
        $page = Storyfeed::feed()->{$mode}()->simplePaginate(2, page: $number);
        array_push($seen, ...$page->toArray()['data']);

        expect($page->currentPage())->toBe($number)
            ->and($page->items())->toHaveCount(min(2, max(0, count($all) - ($number - 1) * 2)));

        if (! $page->hasMorePages()) {
            break;
        }
    }

    expect($seen)->toBe($all)
        ->and(count($all))->toBeGreaterThan(4)
        ->and($mode === 'log' || collect($all)->contains('kind', 'group'))->toBeTrue();
})->with(['log', 'live']);

it('is Laravel\'s simple paginator JSON plus the two feed keys', function () {
    $this->app->instance('request', Request::create('https://example.test/feed?page=2'));
    $page = Storyfeed::feed()->log()->simplePaginate(3);
    $payload = $page->toArray();

    expect($page)->toBeInstanceOf(PaginatorContract::class)
        ->and($page)->toBeInstanceOf(FeedSimplePaginator::class)
        ->and($page[0])->toBeInstanceOf(FeedItem::class)
        ->and(array_keys($payload))->toBe([
            'current_page', 'current_page_url', 'data', 'first_page_url', 'from', 'next_page_url',
            'path', 'per_page', 'prev_page_url', 'to', 'payload_version', 'sync_token',
        ])
        ->and($payload['current_page'])->toBe(2)
        ->and($payload['from'])->toBe(4)
        ->and($payload['to'])->toBe(6)
        ->and($payload['data'])->toBe(array_slice(Storyfeed::feed()->log()->limit(30)->get()->toArray(), 3, 3))
        ->and($payload['next_page_url'])->toBe('https://example.test/feed?page=3')
        ->and($payload['prev_page_url'])->toBe('https://example.test/feed?page=1')
        ->and($payload['payload_version'])->toBe(1)
        ->and($payload['sync_token'])->toBe($page->syncToken())
        ->and(json_decode($page->toJson(), true))->toBe($payload);
});

it('reads an empty last page past the end', function () {
    $page = Storyfeed::feed()->log()->simplePaginate(5, page: 9);

    expect($page->isEmpty())->toBeTrue()
        ->and($page->hasMorePages())->toBeFalse();
});

it('honours the page name and rejects nonpositive page sizes', function () {
    $this->app->instance('request', Request::create('https://example.test/feed?feed_page=3'));
    $page = Storyfeed::feed()->log()->simplePaginate(2, 'feed_page');

    expect($page->currentPage())->toBe(3)
        ->and($page->nextPageUrl())->toBe('https://example.test/feed?feed_page=4')
        ->and(fn () => Storyfeed::feed()->simplePaginate(0))->toThrow(InvalidArgumentException::class);
});

it('pages a source by number as it pages the database', function () {
    $items = collect(range(1, 5))->map(fn ($i) => Entry::make('ship', now()->subDays($i), object: ['type' => 'release', 'label' => "v0.{$i}.0"]))->all();
    $page = Storyfeed::feed()->source(new ArraySource($items))->log()->simplePaginate(2, page: 2);

    expect($page->getCollection()->pluck('object.label')->all())->toBe(['v0.3.0', 'v0.4.0'])
        ->and($page->hasMorePages())->toBeTrue();
});

it('offers no length-aware paginate()', function () {
    expect(fn () => Storyfeed::feed()->paginate())
        ->toThrow(LogicException::class, 'Use cursorPaginate(), or simplePaginate() for numbered pages.');
});
