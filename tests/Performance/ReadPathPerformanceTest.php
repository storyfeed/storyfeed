<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Tests\Fixtures\NestedReadPathMeasurement;
use Storyfeed\Tests\Fixtures\ReadPathHistory;
use Storyfeed\Tests\Fixtures\ReadPathMeasurement;

it('reads Live within a 300ms p50 budget at the requested scale', function () {
    $size = (int) env('STORYFEED_READ_SIZE', 100_000);
    $report = ['status' => 'preflight', 'requested_activities' => $size, 'reads' => []];
    $write = function () use (&$report): void {
        if ($path = env('STORYFEED_READ_REPORT')) {
            file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    };
    $write();
    $seedStart = null;
    try {
        // Check the harness before investing in a multi-million-row seed.
        expect($size)->toBeGreaterThanOrEqual(1000);
        expect(DB::transactionLevel())->toBe(0)
            ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse()
            ->and(DB::table('feed_activities')->count())->toBe(0);
        $report['status'] = 'seeding';
        $write();
        $seedStart = hrtime(true);
        ReadPathHistory::seed($size);
        $report['seed_seconds'] = (hrtime(true) - $seedStart) / 1e9;
        expect(DB::table('feed_activities')->count())->toBe($size)
            ->and(DB::transactionLevel())->toBe(0);
        $report['status'] = 'measuring';
        $write();
        ReadPathMeasurement::run(onRead: function (array $partial) use (&$report, $write): void {
            $report = array_replace($report, $partial);
            $write();
        });
        foreach ($report['reads'] as $read) {
            fwrite(STDERR, sprintf("\n%s curate=%s page=%d: %.1fms p50 / %.1fms p95, %d queries", $read['mode'], $read['curate'] ? 'true' : 'false', $read['page'], $read['p50_ms'], $read['p95_ms'], $read['queries']));
            if ($read['page'] === 1) {
                expect($read['p50_ms'])->toBeLessThan(300);
            }
        }
        fwrite(STDERR, "\n");
        if ($size === 100_000) {
            $nested = NestedReadPathMeasurement::run();
            fwrite(STDERR, json_encode($nested, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n");
            expect($nested['after']['live_p50_ms'])->toBeLessThan(300)
                ->and($nested['after']['involving_p50_ms'])->toBeLessThan(300)
                ->and($nested['writes']['nested']['rows_per_activity'])->toBe(7);
        }
        $report['status'] = 'passed';
    } catch (Throwable $error) {
        $report['failed_stage'] = $report['status'];
        $report['status'] = 'failed';
        $report['error'] = ['class' => $error::class, 'message' => substr($error->getMessage(), 0, 4000)];
        if ($seedStart !== null && ! isset($report['seed_seconds'])) {
            $report['seed_seconds'] = (hrtime(true) - $seedStart) / 1e9;
        }
        throw $error;
    } finally {
        $write();
    }
})->group('read-performance')->skip(fn () => ! env('STORYFEED_READ_BENCH'), 'Opt in with STORYFEED_READ_BENCH=1; the CI performance cell runs this.');
