<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Storyfeed\Actions\CloseBatches;
use Storyfeed\Events\BatchClosed;
use Storyfeed\Models\Batch;

// Adapted from W98: independent PostgreSQL processes, synchronized after
// both sweepers selected the open rows and before either writes a close.
it('closes each batch once across overlapping PostgreSQL sweepers', function () {
    if (! function_exists('pcntl_fork') || ! getenv('W102_PG_DATABASE')) {
        $this->markTestSkipped('Opt-in two-process probe: set W102_PG_DATABASE to a disposable local PostgreSQL database; requires pcntl.');
    }
    $directory = sys_get_temp_dir().'/storyfeed-w102-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $schema = 'w102_'.bin2hex(random_bytes(6));
    $previous = config('database.connections.testing');
    config()->set('database.connections.testing', [
        'driver' => 'pgsql', 'host' => '/tmp', 'port' => 5432,
        'database' => getenv('W102_PG_DATABASE'), 'username' => 'postgres',
        'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => $schema,
    ]);
    DB::purge('testing');
    DB::statement('CREATE SCHEMA '.$schema);
    try {
        $this->defineDatabaseMigrations();
        foreach (range(1, 3) as $actor) {
            Batch::query()->create([
                'actor_type' => 'user', 'actor_id' => $actor,
                'opened_at' => now(), 'last_activity_at' => now(),
                'activities_count' => 1,
            ]);
        }
        // Both sweepers select the same open rows before either writes a close.
        $this->travel(11)->minutes();
        config()->set('storyfeed.grouping.composite.auto', false);
        DB::disconnect('testing');
        $children = [];
        foreach ([1, 2] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('fork failed');
            }
            if ($pid === 0) {
                $events = 0;
                $result = [];
                try {
                    DB::purge('testing');
                    Event::listen(BatchClosed::class, function () use (&$events) {
                        $events++;
                    });
                    $waited = false;
                    DB::connection()->beforeExecuting(function ($query) use ($directory, $worker, &$waited) {
                        if ($waited || ! str_starts_with($query, 'update "feed_batches"')) {
                            return;
                        }
                        $waited = true;
                        touch($directory.'/close-ready-'.$worker);
                        $deadline = microtime(true) + 10;
                        while (! file_exists($directory.'/close-ready-'.(3 - $worker))) {
                            if (microtime(true) > $deadline) {
                                throw new RuntimeException('close barrier timed out');
                            }
                            usleep(1000);
                        }
                    });
                    $result['closed'] = (new CloseBatches)();
                    $result['events'] = $events;
                } catch (Throwable $exception) {
                    $result['fatal'] = $exception->getMessage();
                }
                file_put_contents($directory.'/close-'.$worker.'.json', json_encode($result));
                DB::disconnect('testing');
                exit(isset($result['closed']) ? 0 : 1);
            }
            $children[] = $pid;
        }
        $statuses = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[] = pcntl_wexitstatus($status);
        }
        $closeResults = array_map(fn ($worker) => json_decode(file_get_contents($directory.'/close-'.$worker.'.json'), true), [1, 2]);
        $this->assertSame([0, 0], $statuses, json_encode($closeResults));
        expect(array_sum(array_column($closeResults, 'closed')))->toBe(3)
            ->and(array_sum(array_column($closeResults, 'events')))->toBe(3);
        foreach ($closeResults as $result) {
            expect($result['events'])->toBe($result['closed']);
        }
        DB::purge('testing');
        expect(Batch::query()->whereNotNull('closed_at')->count())->toBe(3);
        file_put_contents(sys_get_temp_dir().'/storyfeed-w102-concurrency.jsonl', json_encode([
            'engine' => DB::selectOne('select version()')->version,
            'close_sweepers' => $closeResults,
            'closed_rows' => Batch::query()->whereNotNull('closed_at')->count(),
        ]).PHP_EOL, FILE_APPEND);
    } finally {
        DB::purge('testing');
        DB::statement('DROP SCHEMA '.$schema.' CASCADE');
        config()->set('database.connections.testing', $previous);
        DB::purge('testing');
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
