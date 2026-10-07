<?php

// Opt-in profiling command. Use ONLY disposable databases: the initial run
// recreates the schema. --reuse keeps the seeded database for query experiments.
// SQLite: php benchmarks/read-path.php --size=100000 --report=build/read.json
// Engines use the same STORYFEED_TEST_* variables as tests/TestCase.php.
require __DIR__.'/../vendor/autoload.php';

use Illuminate\Support\Facades\DB;
use Storyfeed\FeedBuilder;
use Storyfeed\Tests\Fixtures\ReadPathHistory;
use Storyfeed\Tests\Fixtures\ReadPathMeasurement;
use Storyfeed\Tests\Fixtures\ReadPathOracle;
use Storyfeed\Tests\TestCase;

final class ReadPathBenchmarkCase extends TestCase
{
    public bool $reuse = false;

    public bool $seedOnly = false;

    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        if (env('STORYFEED_TEST_DB') === null) {
            config()->set('database.connections.testing', ['driver' => 'sqlite', 'database' => __DIR__.'/../build/read-path/benchmark.sqlite', 'prefix' => '']);
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        if (! $this->reuse) {
            if (env('STORYFEED_TEST_DB') === null) {
                touch(__DIR__.'/../build/read-path/benchmark.sqlite');
                DB::purge('testing');
            }
            if (DB::getDriverName() === 'sqlite') {
                DB::connection()->getSchemaBuilder()->dropAllTables();
            }
            parent::defineDatabaseMigrations();
        }
    }

    public function benchmark(int $size, string $path, bool $compare): void
    {
        $this->setUp();
        try {
            $start = hrtime(true);
            if (! $this->reuse) {
                ReadPathHistory::seed($size);
            }
            $seconds = $this->reuse ? null : (hrtime(true) - $start) / 1e9;
            if ((int) DB::table('feed_activities')->count() !== $size) {
                throw new RuntimeException('Requested scale does not match stored activities.');
            }
            if (! $this->reuse) {
                file_put_contents($path.'.seed.json', json_encode([
                    'driver' => DB::getDriverName(), 'database' => DB::connection()->getDatabaseName(),
                    'activities' => $size, 'seed_seconds' => $seconds,
                ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            } elseif (is_file($path.'.seed.json')) {
                $seed = json_decode(file_get_contents($path.'.seed.json'), true, flags: JSON_THROW_ON_ERROR);
                if ($seed['activities'] === $size && $seed['database'] === DB::connection()->getDatabaseName() && $seed['driver'] === DB::getDriverName()) {
                    $seconds = $seed['seed_seconds'];
                }
            }
            if ($this->seedOnly) {
                return;
            }
            $reports = [];
            $migration = include __DIR__.'/../database/migrations/add_read_path_indexes_to_feed_groupings_table.php.stub';
            foreach ($compare ? ['before' => ReadPathOracle::class, 'after' => FeedBuilder::class] : ['after' => FeedBuilder::class] as $label => $builder) {
                $label === 'before' ? $migration->down() : $migration->up();
                $report = ReadPathMeasurement::run($builder, function (array $partial) use ($path, &$reports, $label, $seconds): void {
                    $partial['seed_seconds'] = $seconds;
                    $reports[$label] = $partial;
                    file_put_contents($path, json_encode($reports, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                    $read = $partial['reads'][array_key_last($partial['reads'])];
                    printf("%s %s curate=%s page=%d: %.1fms p50 / %.1fms p95, %d queries\n", $label, $read['mode'], $read['curate'] ? 'true' : 'false', $read['page'], $read['p50_ms'], $read['p95_ms'], $read['queries']);
                });
                $report['seed_seconds'] = $seconds;
                $reports[$label] = $report;
                file_put_contents($path, json_encode($reports, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            }
        } finally {
            // This command has no PHPUnit runner/configuration registry.
            // Disconnect here; process exit releases the Testbench app.
            foreach (DB::getConnections() as $connection) {
                $connection->disconnect();
            }
        }
    }
}

$options = getopt('', ['size:', 'report:', 'reuse', 'compare', 'seed-only']);
@mkdir(__DIR__.'/../build/read-path', 0777, true);
$case = new ReadPathBenchmarkCase('benchmark');
$case->reuse = isset($options['reuse']);
$case->seedOnly = isset($options['seed-only']);
$case->benchmark((int) ($options['size'] ?? 100_000), $options['report'] ?? __DIR__.'/../build/read-path/report.json', isset($options['compare']));
