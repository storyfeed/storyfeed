<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adds featured on upgrade, featuring the object on every existing row, with no index', function () {
    $table = 'legacy_featured_activities';
    config(['storyfeed.tables.activities' => $table]);

    Schema::create($table, function (Blueprint $blueprint) {
        $blueprint->id();
        $blueprint->string('verb');
    });
    DB::table($table)->insert(['id' => 1, 'verb' => 'historical']);
    $before = (array) DB::table($table)->first();
    $migration = include __DIR__.'/../../database/migrations/add_featured_to_feed_activities_table.php.stub';

    $migration->up();
    $migration->up();

    DB::table($table)->insert(['id' => 2, 'verb' => 'recorded']);
    DB::table($table)->insert(['id' => 3, 'verb' => 'plain', 'featured' => null]);

    expect(DB::table($table)->orderBy('id')->pluck('featured')->all())->toBe(['object', 'object', null])
        ->and(Schema::getIndexes($table))->toHaveCount(1);

    $migration->down();
    $migration->down();

    expect((array) DB::table($table)->first())->toBe($before);
});
