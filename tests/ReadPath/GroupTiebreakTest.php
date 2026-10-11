<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Sources\ArraySource;

/*
 * #104: at one instant, Live and log() read rows in the same order. A group
 * of one breaks its tie on its activity's id, descending, as log() does,
 * where it used to break it on (axis, hash).
 */

/** Each page's ids, following the cursor to the end. */
function pagedIds(Closure $feed): array
{
    $ids = [];
    $cursor = null;

    do {
        $page = $feed()->limit(1)->cursorPaginate(cursor: $cursor)->toArray();
        $ids = [...$ids, ...array_column($page['data'], 'id')];
        $cursor = $page['next_cursor'];
    } while ($cursor !== null);

    return $ids;
}

it('orders groups of one at one instant as log() does, page after page', function () {
    $at = now();

    $rows = collect(['zebra', 'alpha', 'middle', 'kilo'])
        ->map(fn (string $verb) => Storyfeed::activity($verb)->publishedAt($at)->publish());

    $newestFirst = $rows->reverse()->pluck('uid')->values()->all();

    expect(array_column(Storyfeed::feed()->log()->get()->toArray(), 'id'))->toBe($newestFirst)
        ->and(array_column(Storyfeed::feed()->live()->get()->toArray(), 'id'))->toBe($newestFirst)
        ->and(pagedIds(fn () => Storyfeed::feed()->live()))->toBe($newestFirst);
});

// Both ways round: with hashes deciding, one of the two would fail.
it('orders tied groups by their highest member id', function (string $first, string $second) {
    $at = now();

    $older = collect([Storyfeed::activity($first)->publishedAt($at)->publish(), Storyfeed::activity($first)->publishedAt($at)->publish()]);
    $newer = collect([Storyfeed::activity($second)->publishedAt($at)->publish(), Storyfeed::activity($second)->publishedAt($at)->publish()]);

    $items = Storyfeed::feed()->live()->get()->toArray();

    expect(array_column($items, 'kind'))->toBe(['group', 'group'])
        ->and(array_column($items[0]['children'], 'id'))->toEqualCanonicalizing($newer->pluck('uid')->all())
        ->and(array_column($items[1]['children'], 'id'))->toEqualCanonicalizing($older->pluck('uid')->all());
})->with([['zebra', 'alpha'], ['alpha', 'zebra']]);

it('orders a source\'s groups of one at one instant as its log does', function (array $verbs) {
    $source = fn () => Storyfeed::feed()->source(new ArraySource(
        collect($verbs)->map(fn (string $verb) => ['verb' => $verb, 'published_at' => '2026-10-11 12:00:00'])->all(),
    ));

    $log = array_column($source()->log()->get()->toArray(), 'id');

    expect(array_column($source()->live()->get()->toArray(), 'id'))->toBe($log)
        ->and(pagedIds(fn () => $source()->live()))->toBe($log);
})->with([[['zebra', 'alpha', 'middle']], [['middle', 'alpha', 'zebra']]]);
