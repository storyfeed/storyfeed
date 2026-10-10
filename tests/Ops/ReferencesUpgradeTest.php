<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Support\SchemaState;

// Tables as an app that installed 0.12 has them, then every later migration
// except the reference upgrade, which each test runs itself.
function installFrom012(): void
{
    foreach (array_keys(config('storyfeed.tables')) as $key) {
        Schema::dropIfExists(SchemaState::table($key));
    }

    foreach ([
        'create_feed_activities_table', 'create_feed_snapshots_table', 'create_feed_groupings_table',
        'create_feed_participants_table', 'create_feed_parties_table', 'create_feed_batches_table',
        'create_feed_meta_table', 'add_shape_to_feed_snapshots_table', 'add_body_to_feed_snapshots_table',
        'add_body_forms_to_feed_snapshots_table', 'add_source_updated_at_to_feed_snapshots_table',
        'add_as2_roles_to_feed_activities_table', 'add_precision_to_feed_timestamps',
        'add_meta_to_feed_snapshots_table', 'create_feed_tombstones_table', 'create_feed_batch_locks_table',
        'add_closes_at_to_feed_batches_table',
    ] as $name) {
        (include __DIR__."/../Fixtures/V012Migrations/{$name}.php.stub")->up();
    }

    foreach ([
        'add_ancestors_to_feed_participants_table', 'add_read_path_indexes_to_feed_groupings_table',
        'create_feed_grouping_bursts_table', 'add_time_range_to_feed_activities_table',
        'add_entities_to_feed_activities_table', 'add_location_and_generator_to_feed_activities_table',
        'add_featured_to_feed_activities_table',
    ] as $name) {
        (include __DIR__."/../../database/migrations/{$name}.php.stub")->up();
    }

    $now = now();
    DB::table('feed_tombstones')->insert(['id' => 3, 'model_type' => 'delivery', 'model_id' => '5', 'created_at' => $now, 'updated_at' => $now]);
    DB::table('feed_activities')->insert([
        'id' => 1, 'uid' => '01K00000000000000000000001', 'verb' => 'confirm',
        'actor_type' => 'user', 'actor_id' => 7, 'object_type' => 'storyfeed.tombstone', 'object_id' => 3,
        'instrument_type' => 'delivery', 'instrument_id' => 5, 'cached_actor_id' => 11,
        'published_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
    DB::table('feed_snapshots')->insert(['id' => 11, 'model_type' => 'user', 'model_id' => 7, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('feed_batches')->insert(['id' => 1, 'uid' => '01K00000000000000000000002', 'actor_type' => 'user', 'actor_id' => 7, 'opened_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    DB::table('feed_participants')->insert(['activity_id' => 1, 'role' => 'actor', 'entity_type' => 'user', 'entity_id' => '7', 'published_at' => $now]);
    DB::table('feed_batch_locks')->insert(['actor_type' => 'user', 'actor_id' => '7']);
}

function referenceUpgrade(): object
{
    return include __DIR__.'/../../database/migrations/change_feed_references_to_strings.php.stub';
}

function legacyMisshapen(): array
{
    $bigints = [
        'feed_activities' => ['actor_id', 'object_id', 'target_id', 'context_id', 'origin_id', 'result_id', 'instrument_id'],
        'feed_snapshots' => ['model_id'],
        'feed_batches' => ['actor_id'],
    ];

    // SQLite ignores varchar lengths and collations, so only the bigints differ there.
    return Schema::getConnection()->getDriverName() === 'sqlite' ? $bigints : [
        ...$bigints,
        'feed_participants' => ['entity_id'],
        'feed_tombstones' => ['model_id'],
        'feed_batch_locks' => ['actor_id'],
    ];
}

it('finds no misshapen references on a fresh install', function () {
    expect(SchemaState::misshapenReferences())->toBe([]);
});

it('converts 0.12 reference columns to strings and leaves package keys alone', function () {
    installFrom012();
    $cached = collect(Schema::getColumns('feed_activities'))->firstWhere('name', 'cached_actor_id')['type'];

    expect(collect(SchemaState::misshapenReferences())->map(fn ($columns) => collect($columns)->sort()->values()->all())->all())
        ->toEqualCanonicalizing(collect(legacyMisshapen())->map(fn ($columns) => collect($columns)->sort()->values()->all())->all());

    referenceUpgrade()->up();

    expect(SchemaState::misshapenReferences())->toBe([])
        ->and(collect(Schema::getColumns('feed_activities'))->firstWhere('name', 'cached_actor_id')['type'])->toBe($cached)
        ->and(DB::table('feed_activities')->first())
        ->actor_id->toBe('7')->object_id->toBe('3')->instrument_id->toBe('5')->target_id->toBeNull()
        ->and(DB::table('feed_snapshots')->value('model_id'))->toBe('7')
        ->and(DB::table('feed_batches')->value('actor_id'))->toBe('7')
        ->and(Schema::hasIndex('feed_activities', ['object_type', 'object_id', 'published_at', 'id']))->toBeTrue()
        ->and(Schema::hasIndex('feed_snapshots', ['model_type', 'model_id'], 'unique'))->toBeTrue();

    // A rerun finds nothing to change.
    referenceUpgrade()->up();
    expect(SchemaState::misshapenReferences())->toBe([]);
});

it('reports pre-0.13 references in doctor and names them when other checks throw', function () {
    installFrom012();

    $findings = collect(Storyfeed::doctor()->all());
    $shape = $findings->where('code', 'references.shape');

    expect($shape->pluck('subject.table')->sort()->values()->all())->toBe(collect(legacyMisshapen())->keys()->sort()->values()->all());
    // PostgreSQL refuses varchar = bigint, so tombstone joins throw until the upgrade runs.
    $failed = $findings->where('code', 'doctor.check_failed');
    if (Schema::getConnection()->getDriverName() === 'pgsql') {
        expect($failed)->not->toBeEmpty();
    }
    $failed->each(fn ($finding) => expect($finding->message)->toContain('references.shape'));

    referenceUpgrade()->up();

    $codes = collect(Storyfeed::doctor()->all())->pluck('code');
    expect($codes)->not->toContain('references.shape')->not->toContain('doctor.check_failed');
});

it('casts back to the 0.12 shapes where the data allows', function () {
    installFrom012();
    referenceUpgrade()->up();

    referenceUpgrade()->down();

    expect(collect(SchemaState::misshapenReferences())->keys()->sort()->values()->all())
        ->toBe(collect(legacyMisshapen())->keys()->sort()->values()->all())
        ->and((string) DB::table('feed_activities')->value('actor_id'))->toBe('7')
        ->and((string) DB::table('feed_snapshots')->value('model_id'))->toBe('7');

    referenceUpgrade()->up();
    DB::table('feed_activities')->update(['object_id' => '01K00000000000000000000003']);

    expect(fn () => referenceUpgrade()->down())->toThrow(RuntimeException::class, 'feed_activities.object_id');
    expect(SchemaState::misshapenReferences())->toBe([]);
});
