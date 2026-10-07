<?php

use Storyfeed\Tests\Fixtures\ReadPathHistory;
use Storyfeed\Tests\Fixtures\ReadPathMeasurement;

it('reads Live and Summary within a 300ms p50 budget at the requested scale', function () {
    $size = (int) env('STORYFEED_READ_SIZE', 100_000);
    $seedStart = hrtime(true);
    ReadPathHistory::seed($size);
    $seedSeconds = (hrtime(true) - $seedStart) / 1e9;
    $report = ReadPathMeasurement::run();
    $report['seed_seconds'] = $seedSeconds;
    if ($path = env('STORYFEED_READ_REPORT')) {
        file_put_contents($path, json_encode($report, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
    foreach ($report['reads'] as $read) {
        fwrite(STDERR, sprintf("\n%s curate=%s page=%d: %.1fms p50 / %.1fms p95, %d queries", $read['mode'], $read['curate'] ? 'true' : 'false', $read['page'], $read['p50_ms'], $read['p95_ms'], $read['queries']));
        if ($read['page'] === 1) {
            expect($read['p50_ms'])->toBeLessThan(300);
        }
    }
    fwrite(STDERR, "\n");
})->group('read-performance')->skip(fn () => ! env('STORYFEED_READ_BENCH'), 'Opt in with STORYFEED_READ_BENCH=1; the CI performance cell runs this.');
