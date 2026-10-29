<?php

use Illuminate\Http\Request;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Sources\ArraySource;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $this->omar = User::create(['name' => 'Omar', 'email' => 'omar@example.com']);
});

/** Node ids, newest first, following next_cursor to the end. */
function forwardPages($feed, int $perPage): array
{
    $pages = [];
    $cursor = null;

    do {
        $page = $feed->cursorPaginate($perPage, cursor: $cursor);
        $pages[] = $page;
        $cursor = $page->nextCursor();
    } while ($cursor !== null);

    return $pages;
}

/** The page's nodes as plain arrays, for comparing whole nodes. */
function nodes($page): array
{
    return json_decode(json_encode($page->items()), true);
}

function ids($page): array
{
    return collect($page->items())->pluck('id')->all();
}

/** A mixed history: Sally's runs of pings (groups) interleaved with solo visits. */
function mixedHistory($test): void
{
    $minute = 40;

    foreach ([3, 1, 4, 1, 2, 1, 5] as $i => $run) {
        foreach (range(1, $run) as $n) {
            Storyfeed::activity()->actor($i % 2 === 0 ? $test->sally : $test->omar)->verb($i % 2 === 0 ? 'ping' : 'visit')
                ->publishedAt(now()->subMinutes($minute--))->publish();
        }
        Storyfeed::activity('solo-'.$i)->publishedAt(now()->subMinutes($minute--))->publish();
    }
}

it('fills no previous cursor on the first page and one from the second page on', function (string $mode) {
    mixedHistory($this);
    $feed = Storyfeed::feed()->{$mode}();

    $pages = forwardPages($feed, 3);

    expect(count($pages))->toBeGreaterThan(2)
        ->and($pages[0]->previousCursor())->toBeNull()
        ->and($pages[0]->previousPageUrl())->toBeNull()
        ->and($pages[0]->onFirstPage())->toBeTrue();

    foreach (array_slice($pages, 1) as $page) {
        expect($page->previousCursor())->not->toBeNull()
            ->and($page->previousPageUrl())->not->toBeNull()
            ->and($page->onFirstPage())->toBeFalse();
    }
})->with(['log', 'live']);

it('pages backward over exactly the nodes the forward pass produced', function (string $mode, int $perPage) {
    mixedHistory($this);
    $feed = Storyfeed::feed()->{$mode}();

    $forward = forwardPages($feed, $perPage);
    $everything = array_merge(...array_map(ids(...), $forward));

    // From the last page, walk back to the first.
    $page = end($forward);
    $backward = [ids($page)];

    while ($page->previousCursor() !== null) {
        $page = $feed->cursorPaginate($perPage, cursor: $page->previousCursor());
        array_unshift($backward, ids($page));
    }

    // Each page is the one the forward pass read, not just the same rows overall.
    expect($backward)->toBe(array_map(ids(...), $forward))
        ->and(array_merge(...$backward))->toBe($everything);
})->with(['log', 'live'])->with([1, 2, 3, 5]);

it('reads the same nodes backward when a group spans the page boundary', function () {
    mixedHistory($this);
    $feed = Storyfeed::feed()->live();

    $forward = forwardPages($feed, 2);
    $nodes = array_merge(...array_map(nodes(...), $forward));

    $page = end($forward);
    $back = array_reverse(nodes($page));

    while ($page->previousCursor() !== null) {
        $page = $feed->cursorPaginate(2, cursor: $page->previousCursor());
        array_push($back, ...array_reverse(nodes($page)));
    }

    // Full nodes, including each group's count and children, not only ids.
    expect(array_reverse($back))->toBe($nodes)
        ->and(collect($nodes)->where('kind', 'group')->isNotEmpty())->toBeTrue();
});

it('can page forward again from a page read backward', function () {
    mixedHistory($this);
    $feed = Storyfeed::feed()->live();
    $forward = forwardPages($feed, 2);

    $back = $feed->cursorPaginate(2, cursor: $forward[3]->previousCursor());

    expect(ids($back))->toBe(ids($forward[2]))
        ->and($back->nextCursor())->not->toBeNull()
        ->and(ids($feed->cursorPaginate(2, cursor: $back->nextCursor())))->toBe(ids($forward[3]));
});

it('returns a null previous cursor at the newest page when reading backward', function () {
    mixedHistory($this);
    $feed = Storyfeed::feed()->log();
    $forward = forwardPages($feed, 4);

    $newest = $feed->cursorPaginate(4, cursor: $forward[1]->previousCursor());

    expect(ids($newest))->toBe(ids($forward[0]))
        ->and($newest->previousCursor())->toBeNull()
        ->and($newest->onFirstPage())->toBeTrue()
        ->and($newest->nextCursor())->not->toBeNull();
});

it('serves the previous cursor through its page url from a request', function () {
    mixedHistory($this);
    $feed = Storyfeed::feed()->log();
    $second = $feed->cursorPaginate(3, cursor: $feed->cursorPaginate(3)->nextCursor());

    $this->app->instance('request', Request::create($second->previousPageUrl()));
    $first = $feed->cursorPaginate(3);

    expect(ids($first))->toBe(ids($feed->cursorPaginate(3)));
});

it('pages a group\'s members backward', function () {
    foreach (range(1, 9) as $i) {
        Storyfeed::activity()->actor($this->sally)->verb('ping')->publishedAt(now()->subMinutes(10 - $i))->publish();
    }
    $feed = Storyfeed::feed();
    $group = $feed->get()->toArray()[0]['id'];

    $pages = [];
    $cursor = null;
    do {
        $pages[] = $page = $feed->members($group, 4, cursor: $cursor);
        $cursor = $page->nextCursor();
    } while ($cursor !== null);

    $back = [ids(end($pages))];
    $page = end($pages);
    while ($page->previousCursor() !== null) {
        $page = $feed->members($group, 4, cursor: $page->previousCursor());
        array_unshift($back, ids($page));
    }

    expect($back)->toBe(array_map(ids(...), $pages));
});

it('pages backward over an in-memory source', function () {
    $source = new ArraySource(collect(range(1, 7))->map(fn (int $i) => [
        'verb' => 'visit', 'published_at' => now()->subMinutes(20 - $i),
    ])->all());
    $feed = Storyfeed::feed()->source($source)->log();

    $forward = forwardPages($feed, 3);
    $page = end($forward);
    $back = [ids($page)];
    while ($page->previousCursor() !== null) {
        $page = $feed->cursorPaginate(3, cursor: $page->previousCursor());
        array_unshift($back, ids($page));
    }

    expect($forward[0]->previousCursor())->toBeNull()
        ->and($back)->toBe(array_map(ids(...), $forward));
});
