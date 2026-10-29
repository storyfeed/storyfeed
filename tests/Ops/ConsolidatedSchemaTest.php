<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Support\SchemaState;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * The consolidated migrations build the schema the #58 index audit settled
 * on, measured on MySQL 8.4 and PostgreSQL 18 at a million activities. Each
 * index is listed by its columns, so the test holds on every engine whatever
 * the engine names them.
 */
function indexes(string $key): array
{
    return collect(Schema::getIndexes(SchemaState::table($key)))
        ->map(fn (array $index): string => ($index['primary'] ? 'primary ' : ($index['unique'] ? 'unique ' : '')).implode(',', $index['columns']))
        ->sort()
        ->values()
        ->all();
}

function columns(string $key): array
{
    return collect(Schema::getColumnListing(SchemaState::table($key)))->sort()->values()->all();
}

it('builds exactly the audited indexes', function (string $key, array $expected) {
    sort($expected);

    expect(indexes($key))->toBe($expected);
})->with([
    'activities' => ['activities', [
        'primary id',
        'unique uid',
        'verb',
        'published_at,id',
        'actor_type,actor_id,published_at,id',
        'object_type,object_id,published_at,id',
        'target_type,target_id,published_at,id',
        'context_type,context_id,published_at,id',
        'object_type,object_id,verb,published_at',
        'origin_type,origin_id',
        'result_type,result_id',
        'instrument_type,instrument_id',
        'location_type,location_id',
        'generator_type,generator_id',
        'cached_actor_id',
        'cached_object_id',
        'cached_target_id',
        'cached_context_id',
    ]],
    'batches' => ['batches', ['primary id', 'unique uid', 'actor_type,actor_id', 'closed_at']],
    'batch_locks' => ['batch_locks', ['primary actor_type,actor_id']],
    'grouping_bursts' => ['grouping_bursts', ['primary key']],
    'groupings' => ['groupings', [
        'primary id',
        'unique activity_id,bucket',
        'activity_id,winner,bucket',
        'bucket,hash,activity_id,winner',
    ]],
    'meta' => ['meta', ['primary id', 'unique key']],
    'participants' => ['participants', [
        'primary activity_id,entity_type,entity_id',
        'entity_type,entity_id,published_at,activity_id,distance',
    ]],
    'parties' => ['parties', ['primary id', 'unique key']],
    'snapshots' => ['snapshots', ['primary id', 'unique model_type,model_id', 'model_type,shape']],
    'tombstones' => ['tombstones', ['primary id', 'unique model_type,model_id']],
]);

it('keys participants by identity, with no id and no write-only timestamps', function () {
    expect(columns('participants'))->toBe(['activity_id', 'distance', 'entity_id', 'entity_type', 'published_at', 'role']);
});

it('drops the write-only grouping timestamps', function () {
    expect(columns('groupings'))->toBe(['activity_id', 'bucket', 'hash', 'id', 'winner']);
});

it('drops the snapshot component column, which nothing read or wrote', function () {
    expect(Schema::hasColumn(SchemaState::table('snapshots'), 'component'))->toBeFalse();
});

it('carries every column the write path depends on', function () {
    foreach (SchemaState::EXPECTED as $key => $expected) {
        expect(SchemaState::current($key))->toBeTrue("{$key} is missing a column it should be created with");
    }

    expect(SchemaState::misshapenReferences())->toBe([]);
});

it('writes participants and groupings to the consolidated tables', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    Storyfeed::activity()->actor($user)->verb('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    expect(DB::table(SchemaState::table('participants'))->count())->toBe(2)
        ->and(DB::table(SchemaState::table('groupings'))->count())->toBeGreaterThan(0);
});

it('skips every table an install from before the consolidation already has', function () {
    $stubs = glob(__DIR__.'/../../database/migrations/*.php.stub');

    // The tables the harness built stand in for an existing install: running
    // the republished migrations against them must change nothing.
    $schema = fn (): array => collect(array_keys(SchemaState::EXPECTED + SchemaState::REFERENCES + ['parties' => [], 'meta' => [], 'grouping_bursts' => []]))
        ->mapWithKeys(fn (string $key): array => [$key => [columns($key), indexes($key)]])
        ->all();
    $before = $schema();
    DB::table(SchemaState::table('meta'))->insert(['key' => 'kept', 'value' => '1']);

    foreach ($stubs as $stub) {
        (include $stub)->up();
    }

    expect($schema())->toBe($before)
        ->and(DB::table(SchemaState::table('meta'))->where('key', 'kept')->exists())->toBeTrue();
});
