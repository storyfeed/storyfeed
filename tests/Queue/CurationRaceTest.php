<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Tests\Queue\Fixtures\PlainTarget;

// #103: a publish whose transaction began before an earlier member of its
// cluster committed. Under InnoDB's REPEATABLE READ its read view predates
// that member, so its own curation cannot see the cluster cross min_actors;
// the pass after commit can. PostgreSQL reads committed rows per statement.
// Deterministic with two connections in one process, so it runs in the
// MySQL, MariaDB and PostgreSQL cells; SQLite's :memory: has no second
// connection to the same database.
it('curates a cluster another publish tipped while this one was open', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('Needs a second connection to the same database.');
    }
    config()->set('database.connections.second', config('database.connections.testing'));
    Relation::morphMap(['queue-target' => PlainTarget::class]);

    $object = PlainTarget::create(['name' => 'Shared object']);
    $target = PlainTarget::create(['name' => 'Shared target']);
    $publish = fn (string $actor) => Storyfeed::activity('comment', $object)
        ->actor(PlainTarget::create(['name' => $actor]))->target($target)->publish();
    $publish('Ann');

    $second = DB::connection('second');
    $second->beginTransaction();

    try {
        // InnoDB fixes the read view here, before Bob's publish commits.
        $second->table((new Activity)->getTable())->count();
        $publish('Bob');

        DB::setDefaultConnection('second');
        $publish('Cid');
        $second->commit();
    } finally {
        DB::setDefaultConnection('testing');
        if ($second->transactionLevel() > 0) {
            $second->rollBack();
        }
        DB::purge('second');
    }

    expect(Grouping::query()->where('winner', true)->pluck('bucket')->countBy()->all())->toBe(['actors' => 3])
        ->and(Storyfeed::feed()->live()->get()->toArray())->toHaveCount(1);
});

it('curates again after commit only on InnoDB, and only for a publish that joined an open burst', function () {
    Relation::morphMap(['queue-target' => PlainTarget::class]);
    $object = PlainTarget::create(['name' => 'Shared object']);
    $target = PlainTarget::create(['name' => 'Shared target']);
    $count = 0;
    DB::listen(function ($query) use (&$count) {
        if (str_starts_with($query->sql, 'select') && str_contains($query->sql, 'feed_grouping_bursts')) {
            $count++;
        }
    });
    $locks = function (string $actor) use ($object, $target, &$count): int {
        $count = 0;
        Storyfeed::activity('comment', $object)->actor(PlainTarget::create(['name' => $actor]))->target($target)->publish();

        return $count;
    };

    // Ann opens all five bursts. Bob joins the two keyed without an actor
    // (actors, actors_target): the bursts whose earlier member he could miss.
    $opened = $locks('Ann');
    $joined = $locks('Bob');

    expect($joined - $opened)->toBe(in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) ? 2 : 0);
});

// #103 probe: two forked publishers tip one cluster over min_actors at once,
// meeting at the burst insert so each waits on the other's lock. Before
// persisted bursts every engine left the cluster on `repeat`; the burst lock
// fixed PostgreSQL, and the pass after commit fixes MySQL and MariaDB.
//
// Opt-in and skipped in CI. Point W103_PG_PORT, W103_MYSQL_PORT and
// W103_MARIADB_PORT at disposable local servers on 127.0.0.1 (postgres or
// root, no password; MySQL and MariaDB need a `storyfeed_probe` database).
// Each run appends its outcome to W103_REPORT.
it('curates a cluster two concurrent publishes tip over a threshold', function (string $engine, string $variable) {
    if (! function_exists('pcntl_fork') || ! getenv($variable)) {
        $this->markTestSkipped("Opt-in two-process probe: set {$variable}; requires pcntl.");
    }
    $directory = sys_get_temp_dir().'/storyfeed-103-'.bin2hex(random_bytes(6));
    mkdir($directory);
    $schema = 'race103_'.bin2hex(random_bytes(6));
    $previous = config('database.connections.testing');
    $base = [
        'driver' => $engine, 'host' => '127.0.0.1', 'port' => (int) getenv($variable), 'password' => '', 'prefix' => '',
    ];
    if ($engine === 'pgsql') {
        config()->set('database.connections.testing', [...$base, 'database' => 'postgres', 'username' => 'postgres', 'charset' => 'utf8', 'search_path' => $schema]);
        DB::purge('testing');
        DB::statement('CREATE SCHEMA '.$schema);
    } else {
        config()->set('database.connections.testing', [...$base, 'database' => 'storyfeed_probe', 'username' => 'root', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true]);
        DB::purge('testing');
        DB::statement('CREATE DATABASE '.$schema);
        config()->set('database.connections.testing.database', $schema);
        DB::purge('testing');
    }

    try {
        $this->defineDatabaseMigrations();
        Relation::morphMap(['queue-target' => PlainTarget::class]);

        $object = PlainTarget::create(['name' => 'Shared object']);
        $target = PlainTarget::create(['name' => 'Shared target']);
        $actors = [];
        foreach ([0, 1, 2] as $worker) {
            $actors[$worker] = PlainTarget::create(['name' => 'Actor '.$worker]);
        }
        Storyfeed::activity('comment', $object)->actor($actors[0])->target($target)->publish();

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
                    DB::statement($engine === 'pgsql' ? "SET lock_timeout = '5s'" : 'SET innodb_lock_wait_timeout = 5');
                    $waited = false;
                    DB::connection()->beforeExecuting(function ($query) use ($directory, $worker, &$waited) {
                        if ($waited || ! (str_starts_with($query, 'insert into ') && str_contains($query, 'feed_grouping_bursts'))) {
                            return;
                        }
                        $waited = true;
                        touch($directory.'/ready-'.$worker);
                        $deadline = microtime(true) + 10;
                        while (! file_exists($directory.'/ready-'.(3 - $worker))) {
                            if (microtime(true) > $deadline) {
                                throw new RuntimeException('barrier timed out');
                            }
                            usleep(1000);
                        }
                    });
                    DB::beginTransaction();
                    Storyfeed::activity('comment', $object)->actor($actors[$worker])->target($target)->publish();
                    usleep(100_000);
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
        $results = array_map(fn ($file) => json_decode(file_get_contents($file), true), glob($directory.'/result-*.json'));
        DB::purge('testing');

        $winners = Grouping::query()->where('winner', true)->pluck('bucket')->countBy()->sortKeys()->all();
        file_put_contents(getenv('W103_REPORT') ?: sys_get_temp_dir().'/storyfeed-103.jsonl', json_encode([
            'engine' => DB::selectOne('select version() as version')->version,
            'workers' => $results,
            'activities' => Activity::count(),
            'actors_hashes' => Grouping::where('bucket', 'actors')->distinct()->count('hash'),
            'winners' => $winners,
        ]).PHP_EOL, FILE_APPEND);

        $this->assertSame([0, 0], $statuses, json_encode($results));
        expect(Grouping::where('bucket', 'actors')->distinct()->count('hash'))->toBe(1)
            ->and($winners)->toBe(['actors' => 3]);
    } finally {
        DB::purge('testing');
        if ($engine === 'pgsql') {
            DB::statement('DROP SCHEMA '.$schema.' CASCADE');
        } else {
            config()->set('database.connections.testing.database', 'storyfeed_probe');
            DB::purge('testing');
            DB::statement('DROP DATABASE '.$schema);
        }
        config()->set('database.connections.testing', $previous);
        DB::purge('testing');
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
})->with([
    'pgsql' => ['pgsql', 'W103_PG_PORT'],
    'mysql' => ['mysql', 'W103_MYSQL_PORT'],
    'mariadb' => ['mariadb', 'W103_MARIADB_PORT'],
]);
