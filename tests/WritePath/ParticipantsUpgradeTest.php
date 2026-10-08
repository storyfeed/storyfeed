<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Tests\Fixtures\LegacyParticipantsUpgrade;

it('matches the old participants upgrade row for row across multiple duplicate batches', function (string $name) {
    Schema::drop('feed_participants');
    config()->set('storyfeed.tables.participants', $name);
    $rows = [];
    foreach (range(1, 601) as $activity) {
        foreach (['actor', 'object', 'target', 'context', 'instrument'] as $slot => $role) {
            $rows[] = [
                // Lowest id is deliberately not the first role inserted.
                'id' => $activity * 10 + 5 - $slot,
                'activity_id' => $activity,
                'role' => $role,
                'entity_type' => $slot < 2 ? 'user' : 'project',
                'entity_id' => $slot === 4 ? '01' : '1',
                'published_at' => '2026-10-08 12:00:00',
                'created_at' => '2026-10-08 12:00:00',
                'updated_at' => '2026-10-08 12:00:00',
            ];
        }
    }
    $results = [];
    $deletes = 0;
    DB::listen(function (QueryExecuted $query) use (&$deletes) {
        if (str_starts_with(strtolower($query->sql), 'delete ')) {
            $deletes++;
        }
    });
    foreach ([new LegacyParticipantsUpgrade, include __DIR__.'/../../database/migrations/add_ancestors_to_feed_participants_table.php.stub'] as $migration) {
        (include __DIR__.'/../../database/migrations/create_feed_participants_table.php.stub')->up();
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table($name)->insert($chunk);
        }
        $deletes = 0;
        $migration->up();
        $results[] = DB::table($name)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        if (! $migration instanceof LegacyParticipantsUpgrade) {
            expect($deletes)->toBe(1)
                ->and(Schema::hasIndex($name, ['activity_id', 'entity_type', 'entity_id'], 'unique'))->toBeTrue()
                ->and(Schema::hasIndex($name, ['activity_id', 'role'], 'unique'))->toBeFalse();
        }
        Schema::drop($name);
    }
    expect($results[1])->toBe($results[0])->toHaveCount(601 * 3);
})->with(['feed_participants', 'custom participants']);

it('resumes a partially applied participants upgrade and preserves existing distances on rerun', function (string $phase) {
    $name = 'feed_participants';
    Schema::drop($name);
    (include __DIR__.'/../../database/migrations/create_feed_participants_table.php.stub')->up();
    DB::table($name)->insert([
        ['id' => 9, 'activity_id' => 1, 'role' => 'actor', 'entity_type' => 'user', 'entity_id' => '1'],
        ['id' => 3, 'activity_id' => 1, 'role' => 'object', 'entity_type' => 'user', 'entity_id' => '1'],
        ['id' => 12, 'activity_id' => 2, 'role' => 'object', 'entity_type' => 'user', 'entity_id' => '1'],
    ]);
    if ($phase !== 'initial') {
        Schema::table($name, fn (Blueprint $table) => $table->unsignedTinyInteger('distance')->default(0));
    }
    if (in_array($phase, ['role_index_dropped', 'deduped', 'complete'], true)) {
        Schema::table($name, fn (Blueprint $table) => $table->dropUnique(['activity_id', 'role']));
    }
    if (in_array($phase, ['deduped', 'complete'], true)) {
        DB::table($name)->where('id', 9)->delete();
    }
    if ($phase === 'complete') {
        Schema::table($name, fn (Blueprint $table) => $table->unique(['activity_id', 'entity_type', 'entity_id'], 'feed_participants_activity_entity_unique'));
    }
    $migration = include __DIR__.'/../../database/migrations/add_ancestors_to_feed_participants_table.php.stub';
    $migration->up();
    expect(DB::table($name)->orderBy('id')->pluck('id')->all())->toBe([3, 12])
        ->and(DB::table($name)->where('distance', 0)->count())->toBe(2);
    DB::table($name)->where('id', 12)->update(['role' => 'ancestor', 'distance' => 7]);
    $before = DB::table($name)->orderBy('id')->get()->all();
    $migration->up();
    expect(DB::table($name)->orderBy('id')->get()->all())->toEqual($before);
    $migration->down();
    expect(Schema::hasColumn($name, 'distance'))->toBeFalse()
        ->and(Schema::hasIndex($name, ['activity_id', 'role'], 'unique'))->toBeTrue()
        ->and(DB::table($name)->pluck('id')->all())->toBe([3]);
})->with(['initial', 'distance_added', 'role_index_dropped', 'deduped', 'complete']);
