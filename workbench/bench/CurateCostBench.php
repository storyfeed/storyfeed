<?php

/*
 * Where the curation queries go — a standing cost harness, not a test.
 *
 *   vendor/bin/pest workbench/bench/CurateCostBench.php
 *
 * It is here because a quadratic in `CurateCluster::resettle()` was invisible
 * for months: every correctness test passed, every doctor check was green, and
 * a consumer's hourly curate burned seven million queries in a month before
 * anyone counted (W108, Solo todo 887). The next person changing CurateCluster
 * needs a way to SEE the cost, not just prove the stamps.
 *
 * Two shapes, both measured on the publish path (what every consumer pays on
 * every write) and on a full `storyfeed:curate` pass:
 *
 *   A  singletons  — every activity its own actor/target/object; no axis
 *                    is ever eligible. The floor.
 *   B  dense       — A actors × T targets × R uploads each. Every target's
 *                    `actors` cluster is eligible and WINS; every actor's
 *                    `targets` cluster is eligible and LOSES on priority.
 *                    The eligible loser is the case that was quadratic.
 *
 * Output goes to STDERR so PHPUnit's output strictness leaves it alone.
 */

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Tests\TestCase;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

uses(TestCase::class);

/** @return array{total: int, shapes: array<string, int>} */
function bench_count(Closure $work): array
{
    $shapes = [];
    $total = 0;

    $listener = function ($query) use (&$shapes, &$total): void {
        $total++;
        $sql = preg_replace(['/\d+/', '/\s+/', '/in \([^)]*\)/'], ['?', ' ', 'in (…)'], $query->sql);
        $sql = substr($sql, 0, 96);
        $shapes[$sql] = ($shapes[$sql] ?? 0) + 1;
    };

    DB::listen($listener);
    $work();
    // DB::listen has no unlisten; forget the connection's dispatcher listeners
    // for this event so the next measurement starts clean.
    DB::connection()->getEventDispatcher()->forget('Illuminate\Database\Events\QueryExecuted');

    arsort($shapes);

    return ['total' => $total, 'shapes' => $shapes];
}

/**
 * @param  array{total: int, shapes: array<string, int>}  $publish
 * @param  array{total: int, shapes: array<string, int>}  $curate
 */
function bench_report(string $label, int $activities, array $publish, array $curate): void
{
    $line = sprintf(
        "%-28s n=%-4d publish: %6d (%5.1f/act)   curate: %6d (%5.1f/act)\n",
        $label, $activities,
        $publish['total'], $publish['total'] / $activities,
        $curate['total'], $curate['total'] / $activities,
    );
    fwrite(STDERR, $line);

    foreach (array_slice($curate['shapes'], 0, 6, true) as $sql => $n) {
        fwrite(STDERR, sprintf("    %6d %5.1f/act  %s\n", $n, $n / $activities, $sql));
    }
}

function bench_singletons(int $n): int
{
    foreach (range(1, $n) as $i) {
        Storyfeed::activity('upload')
            ->actor(User::create(['name' => "u{$i}", 'email' => "u{$i}@bench.test"]))
            ->object(Delivery::create(['tracking_number' => "s{$i}"]))
            ->target(Customer::create(['name' => "c{$i}"]))
            ->publish();
    }

    return $n;
}

function bench_dense(int $actors, int $targets, int $repeats): int
{
    $users = collect(range(1, $actors))->map(fn ($i) => User::create(['name' => "a{$i}", 'email' => "a{$i}@bench.test"]));
    $customers = collect(range(1, $targets))->map(fn ($i) => Customer::create(['name' => "t{$i}"]));
    $n = 0;

    foreach (range(1, $repeats) as $r) {
        foreach ($users as $user) {
            foreach ($customers as $customer) {
                Storyfeed::activity('upload')
                    ->actor($user)
                    ->object(Delivery::create(['tracking_number' => 'd'.(++$n)]))
                    ->target($customer)
                    ->publish();
            }
        }
    }

    return $n;
}

it('measures shape A: singletons, nothing eligible', function () {
    $n = 0;
    $publish = bench_count(function () use (&$n) {
        $n = bench_singletons(120);
    });
    $curate = bench_count(fn () => Artisan::call('storyfeed:curate'));

    bench_report('A singletons', $n, $publish, $curate);
    expect($n)->toBe(120);
});

it('measures shape B: dense, actors wins, targets is an eligible loser', function (int $repeats) {
    $n = 0;
    $publish = bench_count(function () use (&$n, $repeats) {
        $n = bench_dense(5, 5, $repeats);
    });
    $curate = bench_count(fn () => Artisan::call('storyfeed:curate'));

    bench_report("B dense 5×5×{$repeats}", $n, $publish, $curate);
    expect($n)->toBe(25 * $repeats);
})->with([1, 2, 4, 8]);
