<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adds nullable AS2 role triples on upgrade with only identity indexes and no backfill', function (string $keyType) {
    $table = 'legacy_as2_activities';
    config(['storyfeed.tables.activities' => $table]);
    Schema::defaultMorphKeyType($keyType);

    try {
        Schema::create($table, function (Blueprint $blueprint) {
            $blueprint->id();
            $blueprint->string('verb');
        });
        DB::table($table)->insert(['id' => 1, 'verb' => 'historical']);
        $before = (array) DB::table($table)->first();
        $migration = include __DIR__.'/../../database/migrations/add_as2_roles_to_feed_activities_table.php.stub';
        DB::enableQueryLog();
        $migration->up();
        $migration->up();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        foreach ($queries as $query) {
            expect(strtolower(ltrim($query['query'])))->not->toStartWith('update ');
        }

        $expected = $before;
        $identity = match ($keyType) {
            'uuid' => '018f3010-791c-7e90-9800-c90214d7b444',
            'ulid' => '01J00000000000000000000000',
            default => 42,
        };
        foreach (['origin', 'result', 'instrument'] as $role) {
            $expected[$role.'_type'] = null;
            $expected[$role.'_id'] = null;
            $expected['cached_'.$role.'_id'] = null;
            expect(Schema::hasIndex($table, [$role.'_type', $role.'_id']))->toBeTrue()
                ->and(Schema::hasIndex($table, ['cached_'.$role.'_id']))->toBeFalse();
        }
        expect((array) DB::table($table)->first())->toBe($expected)
            ->and(Schema::getIndexes($table))->toHaveCount(4); // PK and three morph indexes.

        foreach (['origin', 'result', 'instrument'] as $role) {
            DB::table($table)->where('id', 1)->update([$role.'_type' => 'customer', $role.'_id' => $identity]);
            expect((string) DB::table($table)->value($role.'_id'))->toBe((string) $identity);
        }
        $migration->down();
        $migration->down();
        expect((array) DB::table($table)->first())->toBe($before);
    } finally {
        DB::disableQueryLog();
        Schema::defaultMorphKeyType('int');
    }
})->with(['int', 'uuid', 'ulid']);
