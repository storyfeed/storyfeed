<?php

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Storyfeed\BodyMigrations\BodyRegistry;
use Storyfeed\BodyMigrations\RewriteBodies;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Tests\BodyMigrations\Fixtures\MatureFeed;

it('measures a fifty item page and a mature eager rewrite', function () {
    $path = __DIR__.'/../../build/body-bench-'.bin2hex(random_bytes(8));
    config()->set('cache.default', 'array');
    try {
        MatureFeed::register(1);
        MatureFeed::seed(5100, unversioned: true);
        for ($step = 1; $step <= 5; $step++) {
            MatureFeed::migration($path, $step);
        }
        MatureFeed::register(6);
        $measure = function (bool $upgrade): float {
            Storyfeed::upgradeBodies($upgrade);
            $samples = [];
            for ($i = 0; $i < 31; $i++) {
                $start = hrtime(true);
                $items = Storyfeed::feed()->limit(50)->get()->items();
                expect($items)->toHaveCount(50);
                if ($i > 0) {
                    $samples[] = (hrtime(true) - $start) / 1e6;
                }
            }
            sort($samples);

            return ($samples[14] + $samples[15]) / 2;
        };
        $rawV1 = $measure(false);
        $lazyV1 = $measure(true);
        $start = hrtime(true);
        $result = app(RewriteBodies::class)->run(500);
        $seconds = (hrtime(true) - $start) / 1e9;
        $rawV6 = $measure(false);
        $lazyV6 = $measure(true);
        expect(app(BodyRegistry::class)->findings())->toBe([]);
        $output = [
            'engine' => DB::connection()->getDriverName(), 'php' => PHP_VERSION,
            'activities' => 5100, 'bodies_per_page' => 600, 'samples' => 30, 'statistic' => 'median',
            'raw_v1_ms' => $rawV1, 'lazy_v1_ms' => $lazyV1, 'raw_v6_ms' => $rawV6, 'lazy_v6_ms' => $lazyV6,
            'rewrite_seconds' => $seconds, 'rewrite_rows_per_second' => $result['changed'] / $seconds,
            'rewrite' => $result,
        ];
        file_put_contents(__DIR__.'/../../build/body-benchmark-'.DB::connection()->getDriverName().'.json', json_encode($output, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        expect($result['changed'])->toBe(5103);
    } finally {
        (new Filesystem)->deleteDirectory($path);
    }
});
