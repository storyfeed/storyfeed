<?php

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\MorphKeyType;

it('migrates every app reference as varchar with case sensitive comparison', function () {
    $columns = [
        'feed_activities' => array_map(fn ($role) => $role.'_id', ActivityRoles::STORED),
        'feed_batches' => ['actor_id'], 'feed_snapshots' => ['model_id'],
        'feed_participants' => ['entity_id'], 'feed_batch_locks' => ['actor_id'],
        'feed_tombstones' => ['model_id'],
    ];
    $driver = Schema::getConnection()->getDriverName();
    foreach ($columns as $table => $names) {
        $definitions = collect(Schema::getColumns($table))->keyBy('name');
        foreach ($names as $column) {
            expect($definitions[$column]['type_name'])->toBe('varchar', "$table.$column");
            if (in_array($driver, ['mysql', 'mariadb', 'sqlsrv'])) {
                expect($definitions[$column]['collation'])->toBe($driver === 'sqlsrv' ? 'Latin1_General_100_BIN2' : 'ascii_bin');
            }
        }
    }
    expect(Schema::getColumnType('feed_parties', 'id'))->toContain('int')
        ->and(Schema::getColumnType('feed_tombstones', 'id'))->toContain('int')
        ->and(Schema::getColumnType('feed_activities', 'cached_object_id'))->toContain('int');
});

it('keeps case differing ids distinct in every migrated app reference', function () {
    foreach (['abc', 'ABC'] as $key) {
        $activity = ['uid' => (string) Str::ulid(), 'verb' => 'note'];
        foreach (ActivityRoles::STORED as $role) {
            $activity[$role.'_type'] = 'probe';
            $activity[$role.'_id'] = $key;
        }
        $id = DB::table('feed_activities')->insertGetId($activity);
        DB::table('feed_participants')->insert(['activity_id' => $id, 'role' => 'object', 'entity_type' => 'probe', 'entity_id' => $key]);
        DB::table('feed_batches')->insert(['uid' => (string) Str::ulid(), 'actor_type' => 'probe', 'actor_id' => $key, 'opened_at' => now()]);
        DB::table('feed_batch_locks')->insert(['actor_type' => 'probe', 'actor_id' => $key]);
        foreach (['feed_snapshots', 'feed_tombstones'] as $table) {
            DB::table($table)->insert(['model_type' => 'probe', 'model_id' => $key]);
        }
    }
    $columns = [
        'feed_activities' => array_map(fn ($role) => $role.'_id', ActivityRoles::STORED),
        'feed_batches' => ['actor_id'], 'feed_snapshots' => ['model_id'],
        'feed_participants' => ['entity_id'], 'feed_batch_locks' => ['actor_id'],
        'feed_tombstones' => ['model_id'],
    ];
    foreach ($columns as $table => $names) {
        foreach ($names as $column) {
            foreach (['abc', 'ABC'] as $key) {
                expect(DB::table($table)->where($column, $key)->pluck($column)->all())->toBe([$key], "$table.$column");
            }
        }
    }
});

it('compiles one width 36 binary schema regardless of stale app config', function (string $driver) {
    config()->set('storyfeed.morph_key_type', 'int');
    $connection = match ($driver) {
        'mysql' => new MySqlConnection(null, '', '', ['driver' => $driver]),
        'pgsql' => new PostgresConnection(null, '', '', ['driver' => $driver]),
        'sqlsrv' => new SqlServerConnection(null, '', '', ['driver' => $driver]),
        default => new SQLiteConnection(null, '', '', ['driver' => $driver]),
    };
    $connection->useDefaultSchemaGrammar();
    $blueprint = new Blueprint($connection, 'probe', function (Blueprint $table) use ($driver) {
        $table->create();
        MorphKeyType::nullableMorphs($table, 'actor', $driver);
        MorphKeyType::id($table, 'model_id', $driver);
    });
    $sql = implode(' ', $blueprint->toSql());
    expect($sql)->toContain($driver === 'sqlite' ? 'varchar' : 'varchar(36)')->not->toContain('bigint');
    foreach (['actor_id', 'model_id'] as $name) {
        $column = collect($blueprint->getColumns())->first(fn ($column) => $column->name === $name);
        expect($driver === 'sqlsrv' ? $column->definition : $column->length)->toBe($driver === 'sqlsrv' ? 'varchar(36)' : 36)
            ->and($column->collation)->toBe(match ($driver) {
                'mysql' => 'ascii_bin', 'sqlsrv' => 'Latin1_General_100_BIN2', default => null,
            });
    }
    if ($driver === 'mysql') {
        expect($sql)->toContain("character set ascii collate 'ascii_bin'");
    } elseif ($driver === 'sqlsrv') {
        expect($sql)->toContain('collate Latin1_General_100_BIN2');
    }
})->with(['sqlite', 'mysql', 'pgsql', 'sqlsrv']);

it('always casts the package side of app reference joins', function (string $driver) {
    $connection = match ($driver) {
        'mysql' => new MySqlConnection(null, '', '', ['driver' => $driver]),
        'pgsql' => new PostgresConnection(null, '', '', ['driver' => $driver]),
        'sqlsrv' => new SqlServerConnection(null, '', '', ['driver' => $driver]),
        default => new SQLiteConnection(null, '', '', ['driver' => $driver]),
    };
    $grammar = $connection->getQueryGrammar();
    $sql = $connection->table('feed_tombstones as t')
        ->join('feed_activities as a', MorphKeyType::packageKey($grammar, 't.id'), '=', 'a.object_id')->toSql();
    $type = $driver === 'mysql' ? 'char(36) character set ascii' : 'varchar(36)';
    expect($sql)->toContain('cast('.$grammar->wrap('t.id')." as {$type})".($driver === 'mysql' ? ' collate ascii_bin' : '').' = '.$grammar->wrap('a.object_id'));
})->with(['sqlite', 'mysql', 'pgsql', 'sqlsrv']);
