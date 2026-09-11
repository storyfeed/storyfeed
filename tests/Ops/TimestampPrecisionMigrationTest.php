<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The upgrade migration for timestamps that gained microseconds. Every test
 * in the suite already runs it once over a fresh schema (TestCase applies
 * every stub); these run it AGAIN, over rows shaped the way an existing
 * install holds them.
 */

function precisionMigration(): object
{
    return include __DIR__.'/../../database/migrations/add_precision_to_feed_timestamps.php.stub';
}

function legacyRows(): void
{
    DB::table('feed_activities')->insert([
        'uid' => '01J0000000000000000000LEGA',
        'verb' => 'legacy.row',
        'published_at' => '2026-09-01 10:00:00',
        'created_at' => '2026-09-01 10:00:00',
        'updated_at' => '2026-09-01 10:00:01',
        'deleted_at' => null,
    ]);

    DB::table('feed_participants')->insert([
        'activity_id' => 1,
        'role' => 'actor',
        'entity_type' => 'user',
        'entity_id' => '1',
        'published_at' => '2026-09-01 10:00:00',
    ]);

}

it('widens every column the Activity model writes through its format', function () {
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite columns have no precision; the model format carries it.');
    }

    foreach ([
        'feed_activities' => ['published_at', 'created_at', 'updated_at', 'deleted_at'],
        'feed_participants' => ['published_at'],
    ] as $table => $columns) {
        foreach ($columns as $column) {
            expect(Schema::getColumnType($table, $column, true))->toContain('(6)');
        }
    }
});

it('pads legacy SQLite values to the width the model now writes, so text order stays chronological', function () {
    if (DB::getDriverName() !== 'sqlite') {
        $this->markTestSkipped('Real engines keep the value; only SQLite compares text.');
    }

    legacyRows();

    precisionMigration()->up();

    $activity = DB::table('feed_activities')->first();

    expect($activity->published_at)->toBe('2026-09-01 10:00:00.000000')
        ->and($activity->created_at)->toBe('2026-09-01 10:00:00.000000')
        ->and($activity->updated_at)->toBe('2026-09-01 10:00:01.000000')
        ->and($activity->deleted_at)->toBeNull()
        ->and(DB::table('feed_participants')->value('published_at'))->toBe('2026-09-01 10:00:00.000000');

    // A legacy row sorts before a fractional row in the same second, as text
    // and as time — the property the padding exists to keep.
    DB::table('feed_activities')->insert([
        'uid' => '01J0000000000000000000LATE',
        'verb' => 'later.row',
        'published_at' => '2026-09-01 10:00:00.000001',
        'created_at' => '2026-09-01 10:00:00.000001',
        'updated_at' => '2026-09-01 10:00:00.000001',
    ]);

    expect(DB::table('feed_activities')->orderByDesc('published_at')->pluck('verb')->all())
        ->toBe(['later.row', 'legacy.row']);
});

it('runs twice without touching a value it already widened', function () {
    legacyRows();

    precisionMigration()->up();
    $once = DB::table('feed_activities')->first();

    precisionMigration()->up();
    $twice = DB::table('feed_activities')->first();

    expect($twice)->toEqual($once);
});

it('reverses cleanly', function () {
    legacyRows();

    precisionMigration()->up();
    precisionMigration()->down();

    $activity = DB::table('feed_activities')->first();

    // Values survive either way; on SQLite they return to the legacy width.
    expect(substr((string) $activity->published_at, 0, 19))->toBe('2026-09-01 10:00:00');

    if (DB::getDriverName() === 'sqlite') {
        expect($activity->published_at)->toBe('2026-09-01 10:00:00');
    } else {
        expect(Schema::getColumnType('feed_activities', 'published_at', true))->not->toContain('(6)');
    }

    // And forward again, so the suite's schema is what the next test expects.
    precisionMigration()->up();

    if (DB::getDriverName() !== 'sqlite') {
        expect(Schema::getColumnType('feed_activities', 'published_at', true))->toContain('(6)');
    }
});
