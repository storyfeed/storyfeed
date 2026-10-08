<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\RebuildGroupingBursts;
use Storyfeed\Tests\Fixtures\LegacyBurstRebuild;
use Storyfeed\Tests\Fixtures\NewsroomHistory;

it('profiles a chronological Newsroom burst rebuild at 100K', function () {
    $size = (int) env('STORYFEED_REBUILD_SIZE', 100_000);
    NewsroomHistory::seed($size);
    $queries = 0;
    $sqlMs = 0;
    DB::listen(function (QueryExecuted $query) use (&$queries, &$sqlMs) {
        $queries++;
        $sqlMs += $query->time;
    });
    $legacy = (bool) env('STORYFEED_REBUILD_LEGACY');
    $rebuild = $legacy ? new LegacyBurstRebuild : new RebuildGroupingBursts;
    $start = hrtime(true);
    $stats = $rebuild();
    $seconds = (hrtime(true) - $start) / 1e9;
    $report = ['engine' => DB::getDriverName(), 'fixture_activities' => $size, 'activities' => $stats['processed'], 'seconds' => $seconds, 'activities_per_second' => $stats['processed'] / $seconds, 'legacy' => $legacy, 'milliseconds_per_activity' => $legacy ? array_map(fn ($ms) => $ms / $stats['processed'], $rebuild->milliseconds) : null, 'queries' => $queries, 'sql_ms' => $sqlMs, 'peak_bytes' => memory_get_peak_usage(true), 'stats' => $stats];
    file_put_contents(env('STORYFEED_REBUILD_REPORT', 'build/rebuild-baseline.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    fwrite(STDERR, json_encode($report)."\n");
    expect($stats['processed'])->toBe($legacy ? (int) env('STORYFEED_REBUILD_PROFILE_LIMIT', $size) : $size);
    if (! $legacy) {
        expect(memory_get_peak_usage(true))->toBeLessThan(512 * 1024 * 1024);
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            expect($seconds)->toBeLessThan(300);
        }
    }
})->skip(fn () => ! env('STORYFEED_REBUILD_BENCH'), 'Opt in with STORYFEED_REBUILD_BENCH=1.');
