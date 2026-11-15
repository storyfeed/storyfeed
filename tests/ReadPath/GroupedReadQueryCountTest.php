<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * #254: a grouped read widens its window until the window holds a full page.
 * When the activities between two depths share one instant (a bulk import, a
 * seeded history), the deeper depth finds the same inclusive floor, and the
 * read ran the bucket aggregate and the solo window again with identical SQL
 * and bindings. A depth whose floor did not move now widens without reading.
 */

/** A FeedBuilder that always reads the unbounded aggregate: the oracle. */
final class UnwindowedGroupedReadBuilder extends FeedBuilder
{
    protected function windowDepths(): array
    {
        return [null];
    }
}

/**
 * Two hundred pings by one actor at one instant, then `$solos` later solos:
 * at limit(3) the first two depths (48 and 192) both find that instant, and
 * a window holding the ping group and up to two solos comes up short.
 *
 * @return list<int> the solos' activity ids, oldest first
 */
function sharedInstantHistory(int $solos = 2): array
{
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $at = now()->subDay()->startOfSecond();

    foreach (range(1, 200) as $i) {
        Storyfeed::activity('ping', Customer::create(['name' => "Customer {$i}"]))->actor($sally)->publishedAt($at)->publish();
    }

    return array_map(fn (int $i) => Storyfeed::activity('confirm', Delivery::create(['tracking_number' => "TN-{$i}"]))
        ->actor(User::create(['name' => "User {$i}", 'email' => "user{$i}@example.com"]))
        ->publishedAt(now()->subMinutes(10 - $i))->publish()->getKey(), range(1, $solos));
}

/** @return list<array{query: string, bindings: array<int, mixed>}> every query $read runs */
function groupedReadLog(Closure $read): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $read();

        return array_map(fn (array $entry) => ['query' => $entry['query'], 'bindings' => $entry['bindings']], DB::getQueryLog());
    } finally {
        DB::disableQueryLog();
    }
}

/** @return list<string> each query that ran more than once with the same bindings */
function repeatedQueries(array $log): array
{
    return collect($log)
        ->countBy(fn (array $entry) => $entry['query'].' '.json_encode($entry['bindings']))
        ->filter(fn (int $runs) => $runs > 1)
        ->keys()
        ->all();
}

function bucketScans(array $log): int
{
    return count(array_filter($log, fn (array $entry) => str_contains($entry['query'], 'as last_id')));
}

function windowFloors(array $log): int
{
    return count(array_filter($log, fn (array $entry) => preg_match('/^select ["`]?feed_activities["`]?\.["`]?published_at["`]? from .* offset \d+$/', $entry['query']) === 1));
}

it('runs each grouped query once when two window depths share a floor', function (Closure $read) {
    sharedInstantHistory();

    $log = groupedReadLog(fn () => $read()->toArray());

    expect(repeatedQueries($log))->toBe([])
        ->and(bucketScans($log))->toBe(2)
        // The window did widen past the shared floor, so this is not vacuous.
        ->and(windowFloors($log))->toBe(3);
})->with([
    'get' => fn () => Storyfeed::feed()->live()->children(0)->samples(2)->limit(3)->get(),
    'page' => fn () => Storyfeed::feed()->live()->children(0)->samples(2)->limit(3)->page(),
    'cursorPaginate' => fn () => Storyfeed::feed()->live()->children(0)->samples(2)->cursorPaginate(3),
]);

it('reads the same payload as the unbounded aggregate when depths share a floor', function () {
    // Four solos fill page one; page two, under a cursor, holds the last solo
    // and the ping group, so it widens past the shared floor.
    sharedInstantHistory(solos: 4);
    $repeated = [];

    $walk = function (Closure $feed) use (&$repeated): array {
        $pages = [];
        $cursor = null;

        do {
            $log = groupedReadLog(function () use ($feed, $cursor, &$page) {
                $page = json_encode($feed()->live()->children(0)->samples(2)->cursorPaginate(3, cursor: $cursor));
            });
            $repeated = [...$repeated, ...repeatedQueries($log)];
            $pages[] = $page;
            $cursor = json_decode($page, true)['next_cursor'];
        } while ($cursor !== null && count($pages) < 20);

        return $pages;
    };

    $windowed = $walk(fn () => new FeedBuilder);

    expect($repeated)->toBe([])
        ->and($windowed)->toBe($walk(fn () => new UnwindowedGroupedReadBuilder))
        ->and($windowed)->toHaveCount(2);
});

it('reads fresh on the next read of the same builder', function () {
    [, $newest] = sharedInstantHistory();
    $feed = Storyfeed::feed()->live()->children(0)->samples(2)->limit(3);

    $first = groupedReadLog(function () use ($feed, &$before) {
        $before = $feed->get()->toArray();
    });

    Activity::query()->findOrFail($newest)->delete();

    $second = groupedReadLog(function () use ($feed, &$after) {
        $after = $feed->get()->toArray();
    });

    // Nothing carries over: the second read runs its own window and aggregate,
    // once each, and no longer sees the activity deleted between the reads.
    expect(bucketScans($first))->toBe(2)
        ->and(bucketScans($second))->toBe(2)
        ->and(repeatedQueries($second))->toBe([])
        ->and($before)->toHaveCount(3)
        ->and($after)->toHaveCount(2)
        ->and($after)->toBe(array_slice($before, 1));
});
