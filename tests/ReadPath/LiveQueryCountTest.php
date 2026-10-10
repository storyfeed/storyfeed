<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * #121: a Live page's distinct counts are one query whatever the number of
 * roles, and its snapshots one query whatever the number of filled roles.
 */
beforeEach(function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $acme = Customer::create(['name' => 'Acme']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity('ping', Customer::create(['name' => "Customer {$i}"]))->actor($sally)
            ->publishedAt(now()->subMinutes(10 - $i))->publish();
    }

    foreach (range(1, 2) as $i) {
        $user = User::create(['name' => "User {$i}", 'email' => "user{$i}@example.com"]);
        Storyfeed::activity('confirm', Delivery::create(['tracking_number' => "TN-{$i}"]))->actor($user)->to($acme)
            ->publishedAt(now()->subMinutes(5 - $i))->publish();
    }
});

/** @return list<string> the SQL of every query $read runs */
function liveQueries(Closure $read): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $read();

        return array_column(DB::getQueryLog(), 'query');
    } finally {
        DB::disableQueryLog();
    }
}

function snapshotQueries(array $queries): int
{
    return count(array_filter($queries, fn (string $sql) => preg_match('/from ["`]?feed_snapshots["`]?/', $sql) === 1));
}

it('counts every role of every group on a Live page in one query', function () {
    $queries = liveQueries(fn () => Storyfeed::feed()->live()->get()->toArray());
    $nodes = Storyfeed::feed()->live()->get()->toArray();

    expect(collect($nodes)->where('kind', 'group')->count())->toBe(1)
        ->and(count(array_filter($queries, fn (string $sql) => str_contains($sql, 'as tombstoned'))))->toBe(1);
});

it('loads a Live page\'s snapshots for solos and members in one query', function () {
    $queries = liveQueries(fn () => Storyfeed::feed()->live()->get()->toArray());

    expect(snapshotQueries($queries))->toBe(1);
});

it('loads a Log page\'s snapshots in one query', function () {
    $queries = liveQueries(fn () => Storyfeed::feed()->log()->get()->toArray());
    $nodes = Storyfeed::feed()->log()->get()->toArray();

    expect(snapshotQueries($queries))->toBe(1)
        ->and(array_column(array_column($nodes, 'actor'), 'label'))->toBe(['User 2', 'User 1', 'Sally', 'Sally', 'Sally'])
        ->and(array_map(fn (array $node) => $node['target']['label'] ?? null, $nodes))->toBe(['Acme', 'Acme', null, null, null]);
});
