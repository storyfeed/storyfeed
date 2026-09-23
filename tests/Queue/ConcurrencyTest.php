<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Storyfeed\Actions\CloseBatches;
use Storyfeed\Actions\CurateCluster;
use Storyfeed\Events\BatchClosed;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Batch;
use Storyfeed\Models\Grouping;
use Storyfeed\Tests\Queue\Fixtures\PlainTarget;
use Storyfeed\Tests\Queue\Fixtures\PublishListener;
use Workbench\App\Models\Delivery;

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

// Ported from W98, keeping only the half that is still true. W98's original
// went on to assert two sweepers each emitting BatchClosed for the same three
// batches; W102 fixed that, and the test above now asserts the fix. What
// survives is the half nothing has fixed yet: todo 843, the stale curation
// winner. It is a CHARACTERIZATION of a known defect, not a guarantee — when
// the race is fixed, this test should fail and be rewritten to assert the fix.
it('leaves a stale curation winner when overlapping workers cross a threshold', function () {
    if (! function_exists('pcntl_fork') || ! getenv('W102_PG_DATABASE')) {
        $this->markTestSkipped('Opt-in two-process probe: set W102_PG_DATABASE to a disposable local PostgreSQL database; requires pcntl.');
    }
    $directory = sys_get_temp_dir().'/storyfeed-race-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $schema = 'race_'.bin2hex(random_bytes(6));
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
        Relation::morphMap(['queue-target' => PlainTarget::class]);
        config()->set('queue.connections.database.connection', 'testing');
        DB::statement('CREATE TABLE jobs (id BIGSERIAL PRIMARY KEY, queue TEXT, payload TEXT, attempts INTEGER, reserved_at INTEGER NULL, available_at INTEGER, created_at INTEGER)');

        // Three activities sharing one target. The actors axis needs three
        // distinct actors, so the cluster crosses its threshold only when all
        // three are counted — which is the moment the race exists.
        $target = PlainTarget::create(['name' => 'Shared target']);
        $inputs = [];
        foreach ([0, 1, 2] as $worker) {
            $delivery = Delivery::create(['tracking_number' => 'CONCURRENT-'.$worker]);
            Storyfeed::party('Importer '.$worker);
            $inputs[$worker] = ['delivery' => $delivery->id, 'actor' => 'Importer '.$worker, 'target' => $target->id];
        }

        (new PublishListener)->handle($inputs[0]);
        foreach ([1, 2] as $worker) {
            app('queue')->connection('database')->push(new CallQueuedListener(PublishListener::class, 'handle', [$inputs[$worker]]), '', 'worker-'.$worker);
        }

        DB::disconnect('testing');
        $children = [];
        foreach ([1, 2] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('fork failed');
            }
            if ($pid === 0) {
                $result = ['worker' => $worker];
                try {
                    DB::purge('testing');
                    DB::statement("SET lock_timeout = '5s'");
                    $queue = new DatabaseQueue(DB::connection('testing'), 'jobs');
                    $queue->setContainer(app());
                    $job = $queue->pop('worker-'.$worker);
                    DB::beginTransaction();
                    $job->fire();
                    // Both workers curate before either commits, so neither
                    // read includes the other's row.
                    touch($directory.'/ready-'.$worker);
                    $deadline = microtime(true) + 10;
                    while (! file_exists($directory.'/ready-'.(3 - $worker))) {
                        if (microtime(true) > $deadline) {
                            throw new RuntimeException('worker barrier timed out');
                        }
                        usleep(1000);
                    }
                    DB::commit();
                    $result['published'] = true;
                } catch (Throwable $exception) {
                    $result['fatal'] = $exception->getMessage();
                }
                file_put_contents($directory.'/result-'.$worker.'.json', json_encode($result));
                DB::disconnect('testing');
                exit(isset($result['published']) ? 0 : 1);
            }
            $children[] = $pid;
        }

        $statuses = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[] = pcntl_wexitstatus($status);
        }
        $this->assertSame([0, 0], $statuses, implode(' ', array_map('file_get_contents', glob($directory.'/result-*.json'))));

        DB::purge('testing');

        // No activity is lost and every hash is present. What is wrong is only
        // the winner stamps: three activities, one actors hash, and nothing
        // stamped actors — so a summary read shows three plain rows where one
        // group belongs.
        expect(Activity::count())->toBe(3)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(Grouping::where('bucket', 'actors')->distinct()->count('hash'))->toBe(1)
            ->and(Grouping::where('bucket', 'actors')->where('winner', true)->count())->toBe(0)
            ->and(Grouping::where('bucket', 'repeat')->where('winner', true)->count())->toBe(3);

        // And it heals: re-curating from the stored hashes picks the right
        // winner with no new information. This is what the scheduled hourly
        // `storyfeed:curate` does, which is why the defect is bounded in time.
        foreach (Activity::all() as $activity) {
            (new CurateCluster)($activity);
        }

        expect(Grouping::where('bucket', 'actors')->where('winner', true)->count())->toBe(3);
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

// Todo 843's remaining BatchClosed exposure, fixed by todo 1339: two publishes
// by the SAME actor with no open batch. There is no batch row to lock, so each
// transaction used to open its own — one burst, two batches, two BatchClosed.
// AssignToBatch now upserts the actor's `feed_batch_locks` row first, so the
// second publish waits there and joins the first one's batch.
//
// Workers wait for each other after publishing and before committing, up to a
// deadline: the second is blocked inside publish and never arrives, and the
// probe records that instead of hanging.
//
// Before the lock, measured: PostgreSQL 18 gave a plain-model actor two
// batches, while a Party got one only by accident (its snapshot upsert already
// serialized the two). MySQL and MariaDB, under REPEATABLE READ, did not give
// two batches: both workers took a gap lock on the empty range, both then
// tried to insert into it, and one publish died with a deadlock.
//
// Every case runs on an empty feed and on 300 rows of history. On a handful
// of rows the optimizer may scan instead of using an index, and a locking
// scan on MariaDB takes rows two publishes never share: the empty feed is
// the case that found todo 1349, where curation updated `feed_groupings` by
// `activity_id` and two unrelated first publishes deadlocked. With history
// the indexes are used, so it is the case a grown feed sees.
//
// Opt-in and skipped in CI, so a green matrix says nothing about it. One
// disposable database per engine: W102_PG_DATABASE (socket in /tmp), and
// W102_MYSQL_PORT / W102_MARIADB_PORT (root, no password, 127.0.0.1) with
// W102_MYSQL_DATABASE naming a database to connect through first.
dataset('first batch engines', function () {
    foreach (['pgsql' => 'W102_PG_DATABASE', 'mysql' => 'W102_MYSQL_PORT', 'mariadb' => 'W102_MARIADB_PORT'] as $engine => $variable) {
        foreach (['party', 'plain model', 'two actors', 'two parties'] as $actor) {
            foreach (['empty feed' => 0, '300 rows' => 300] as $size => $rows) {
                yield "{$engine}, {$actor}, {$size}" => [$engine, $variable, $actor, $rows];
            }
        }
    }
});

it('joins two first publishes by one actor into one batch', function (string $engine, string $variable, string $actor, int $rows) {
    if (! function_exists('pcntl_fork') || ! getenv($variable)) {
        $this->markTestSkipped("Opt-in two-process probe: set {$variable} to a disposable local {$engine} database; requires pcntl.");
    }
    $directory = sys_get_temp_dir().'/storyfeed-first-batch-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $schema = 'first_batch_'.bin2hex(random_bytes(6));
    $previous = config('database.connections.testing');
    $mysql = [
        'driver' => $engine, 'host' => '127.0.0.1', 'port' => (int) getenv($variable),
        'database' => getenv('W102_MYSQL_DATABASE') ?: 'storyfeed_w102_probe', 'username' => 'root',
        'password' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
    ];
    if ($engine === 'pgsql') {
        config()->set('database.connections.testing', [
            'driver' => 'pgsql', 'host' => '/tmp', 'port' => 5432,
            'database' => getenv('W102_PG_DATABASE'), 'username' => 'postgres',
            'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => $schema,
        ]);
        DB::purge('testing');
        DB::statement('CREATE SCHEMA '.$schema);
    } else {
        config()->set('database.connections.testing', $mysql);
        DB::purge('testing');
        DB::statement('CREATE DATABASE '.$schema);
        config()->set('database.connections.testing.database', $schema);
        DB::purge('testing');
    }

    try {
        $this->defineDatabaseMigrations();
        Relation::morphMap(['queue-target' => PlainTarget::class]);
        // A Party is Feedable, so both publishes write its snapshot row; a
        // plain model is not, so before the lock nothing shared was written
        // ahead of the batch decision.
        // Two actors is the control: nothing is shared, so each gets a batch
        // and neither publish may wait on, or deadlock with, the other.
        $who = match ($actor) {
            'party' => fn () => ['actor' => Storyfeed::party('Importer')->name],
            'plain model' => fn () => ['plain_actor' => PlainTarget::firstOrCreate(['name' => 'Plain actor'])->id],
            'two actors' => fn (int $worker) => ['plain_actor' => PlainTarget::create(['name' => 'Actor '.$worker])->id],
            'two parties' => fn (int $worker) => ['actor' => Storyfeed::party('Importer '.$worker)->name],
        };
        $inputs = [];
        foreach ([1, 2] as $worker) {
            $inputs[$worker] = ['delivery' => Delivery::create(['tracking_number' => 'FIRST-'.$worker])->id, ...$who($worker)];
        }
        // History by another actor, or none (see the dataset).
        $filler = PlainTarget::create(['name' => 'Filler actor']);
        for ($n = 1; $n <= $rows; $n++) {
            (new PublishListener)->handle(['delivery' => Delivery::create(['tracking_number' => 'FILLER-'.$n])->id, 'plain_actor' => $filler->id]);
        }
        if ($engine !== 'pgsql') {
            foreach (array_intersect_key(config('storyfeed.tables'), array_flip(['activities', 'groupings', 'participants', 'batches', 'batch_locks'])) as $table) {
                DB::statement('ANALYZE TABLE '.$table);
            }
        }
        $history = Activity::count();

        DB::disconnect('testing');
        $children = [];
        foreach ([1, 2] as $worker) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('fork failed');
            }
            if ($pid === 0) {
                $result = ['worker' => $worker];
                try {
                    DB::purge('testing');
                    DB::statement($engine === 'pgsql' ? "SET lock_timeout = '5s'" : 'SET SESSION innodb_lock_wait_timeout = 5');
                    DB::beginTransaction();
                    (new PublishListener)->handle($inputs[$worker]);
                    touch($directory.'/ready-'.$worker);
                    $deadline = microtime(true) + 2;
                    while (! file_exists($directory.'/ready-'.(3 - $worker)) && microtime(true) < $deadline) {
                        usleep(1000);
                    }
                    $result['overlapped'] = file_exists($directory.'/ready-'.(3 - $worker));
                    DB::commit();
                    $result['published'] = true;
                } catch (Throwable $exception) {
                    $result['fatal'] = $exception->getMessage();
                }
                file_put_contents($directory.'/result-'.$worker.'.json', json_encode($result));
                DB::disconnect('testing');
                exit(isset($result['published']) ? 0 : 1);
            }
            $children[] = $pid;
        }

        $statuses = [];
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[] = pcntl_wexitstatus($status);
        }
        $results = array_map(fn ($worker) => json_decode(file_get_contents($directory.'/result-'.$worker.'.json'), true), [1, 2]);

        DB::purge('testing');

        $mine = fn ($query) => $query->whereNot(fn ($query) => $query->where('actor_type', 'queue-target')->where('actor_id', $filler->id));
        $batches = $mine(Batch::query())->count();

        file_put_contents(sys_get_temp_dir().'/storyfeed-843-first-batch.jsonl', json_encode([
            'engine' => DB::selectOne('select version() as version')->version,
            'actor' => $actor,
            'workers' => $results,
            'filler' => $history,
            'activities' => Activity::count() - $history,
            'batches' => $batches,
            'open_batches' => $mine(Batch::query())->whereNull('closed_at')->count(),
            'members' => $mine(Batch::query())->pluck('activities_count')->all(),
        ]).PHP_EOL, FILE_APPEND);

        $this->assertSame([0, 0], $statuses, json_encode($results));
        $expected = str_starts_with($actor, 'two ') ? 2 : 1;
        expect(Activity::count() - $history)->toBe(2)
            ->and($batches)->toBe($expected)
            ->and((int) $mine(Batch::query())->sum('activities_count'))->toBe(2)
            ->and(Grouping::query()->where('bucket', 'batch')->where('activity_id', '>', $history)->distinct()->count('hash'))->toBe($expected);
    } finally {
        DB::purge('testing');
        if ($engine === 'pgsql') {
            DB::statement('DROP SCHEMA '.$schema.' CASCADE');
        } else {
            DB::statement('DROP DATABASE IF EXISTS '.$schema);
        }
        config()->set('database.connections.testing', $previous);
        DB::purge('testing');
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with('first batch engines');
