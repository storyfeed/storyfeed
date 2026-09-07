<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

it('adds only nullable body columns to existing snapshots without rewriting rows and rolls them back', function () {
    $table = 'legacy_body_snapshots';
    config(['storyfeed.tables.snapshots' => $table]);
    Schema::create($table, function (Blueprint $blueprint) {
        $blueprint->id();
        $blueprint->text('data')->nullable();
    });
    DB::table($table)->insert(['id' => 1, 'data' => '{"content":"app value"}']);
    $before = (array) DB::table($table)->first();
    $migration = include __DIR__.'/../../database/migrations/add_body_to_feed_snapshots_table.php.stub';

    DB::enableQueryLog();
    $migration->up();
    $migration->up();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    foreach ($queries as $query) {
        expect(strtolower(ltrim($query['query'])))->not->toStartWith('update ');
    }
    $row = (array) DB::table($table)->first();
    expect($row)->toBe([...$before, 'content' => null, 'mediaType' => null, 'attributedTo' => null]);

    DB::table($table)->where('id', 1)->update(['content' => str_repeat('words ', 1000), 'mediaType' => 'text/plain', 'attributedTo' => 'urn:author:1']);
    expect(DB::table($table)->value('content'))->toBe(str_repeat('words ', 1000));
    $migration->down();
    $migration->down();
    expect((array) DB::table($table)->first())->toBe($before)
        ->and(Schema::hasColumn($table, 'content'))->toBeFalse()
        ->and(Schema::hasColumn($table, 'mediaType'))->toBeFalse()
        ->and(Schema::hasColumn($table, 'attributedTo'))->toBeFalse();
});
