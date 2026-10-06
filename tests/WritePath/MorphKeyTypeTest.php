<?php

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Database\SqlServerConnection;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Support\MorphKeyType;

it('stores every app model reference as a string wide enough for UUIDs', function () {
    config()->set('storyfeed.morph_key_type', 'string');

    Schema::create('morph_key_probe', function (Blueprint $table) {
        MorphKeyType::nullableMorphs($table, 'actor');
        MorphKeyType::id($table, 'model_id');
    });

    expect(Schema::getColumnType('morph_key_probe', 'actor_id'))->toBe('varchar')
        ->and(Schema::getColumnType('morph_key_probe', 'model_id'))->toBe('varchar');

    $blueprint = new Blueprint(Schema::getConnection(), 'width_probe');
    MorphKeyType::id($blueprint, 'model_id');
    expect($blueprint->getColumns()[0]->length)->toBe(36);
});

it('follows Laravel morph key defaults without changing them', function (string $laravel, string $mode) {
    $previous = Builder::$defaultMorphKeyType;
    try {
        Builder::defaultMorphKeyType($laravel);
        config()->set('storyfeed.morph_key_type', null);
        expect(MorphKeyType::mode())->toBe($mode)
            ->and(Builder::$defaultMorphKeyType)->toBe($laravel);
        Schema::create('default_key_probe', function (Blueprint $table) {
            MorphKeyType::nullableMorphs($table, 'actor');
            MorphKeyType::id($table, 'model_id');
        });
        foreach (['actor_id', 'model_id'] as $column) {
            $type = Schema::getColumnType('default_key_probe', $column);
            expect($mode === 'string' ? $type === 'varchar' : str_contains($type, 'int'))->toBeTrue();
        }
    } finally {
        Builder::defaultMorphKeyType($previous);
    }
})->with([['int', 'int'], ['uuid', 'string'], ['ulid', 'string']]);

it('rejects unknown storage modes', function (mixed $mode) {
    config()->set('storyfeed.morph_key_type', $mode);
    expect(fn () => MorphKeyType::mode())->toThrow(InvalidArgumentException::class);
})->with(['uuid', 'ulid', 'integer', '', 1, false]);

it('keeps numeric morph schema SQL identical to Laravel', function (string $driver) {
    config()->set('storyfeed.morph_key_type', 'int');
    $connection = match ($driver) {
        'mysql' => new MySqlConnection(null, '', '', ['driver' => $driver]),
        'pgsql' => new PostgresConnection(null, '', '', ['driver' => $driver]),
        'sqlsrv' => new SqlServerConnection(null, '', '', ['driver' => $driver]),
        default => new SQLiteConnection(null, '', '', ['driver' => $driver]),
    };
    $connection->useDefaultSchemaGrammar();
    $legacy = new Blueprint($connection, 'probe', function (Blueprint $table) {
        $table->create();
        $table->nullableNumericMorphs('actor');
        $table->unsignedBigInteger('model_id');
        $table->string('entity_id');
    });
    $current = new Blueprint($connection, 'probe', function (Blueprint $table) {
        $table->create();
        MorphKeyType::nullableMorphs($table, 'actor');
        MorphKeyType::id($table, 'model_id');
        MorphKeyType::id($table, 'entity_id', legacyString: true);
    });
    expect($current->toSql())->toBe($legacy->toSql());
})->with(['sqlite', 'mysql', 'pgsql', 'sqlsrv']);

it('casts only package keys in string mode across query grammars', function (string $driver) {
    $connection = match ($driver) {
        'mysql' => new MySqlConnection(null, '', '', ['driver' => $driver]),
        'pgsql' => new PostgresConnection(null, '', '', ['driver' => $driver]),
        'sqlsrv' => new SqlServerConnection(null, '', '', ['driver' => $driver]),
        default => new SQLiteConnection(null, '', '', ['driver' => $driver]),
    };
    $legacy = $connection->table('feed_tombstones as t')
        ->join('feed_activities as a', 't.id', '=', 'a.object_id')->toSql();
    config()->set('storyfeed.morph_key_type', 'int');
    $numeric = $connection->table('feed_tombstones as t')
        ->join('feed_activities as a', MorphKeyType::packageKey($connection->getQueryGrammar(), 't.id'), '=', 'a.object_id')->toSql();
    expect($numeric)->toBe($legacy);

    config()->set('storyfeed.morph_key_type', 'string');
    $string = $connection->table('feed_tombstones as t')
        ->join('feed_activities as a', MorphKeyType::packageKey($connection->getQueryGrammar(), 't.id'), '=', 'a.object_id')->toSql();
    $grammar = $connection->getQueryGrammar();
    $type = $driver === 'mysql' ? 'char(36)' : 'varchar(36)';
    expect($string)->toBe(str_replace($grammar->wrap('t.id').' =', 'cast('.$grammar->wrap('t.id')." as {$type}) =", $legacy));
})->with(['sqlite', 'mysql', 'pgsql', 'sqlsrv']);
