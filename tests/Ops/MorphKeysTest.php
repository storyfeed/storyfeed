<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Courier;

it('reports ULID feedables against actual numeric columns', function () {
    $finding = Storyfeed::doctor(['morph_keys'])->withCode('morph_keys.incompatible')->sole();
    expect($finding->subject['model'])->toBe(Courier::class)
        ->and($finding->severity)->toBe(Severity::Error)
        ->and($finding->message)->toContain('storyfeed.morph_key_type', 'migrate');
});

it('still reports numeric migrated columns after config changes', function () {
    config()->set('storyfeed.morph_key_type', 'string');
    expect(Storyfeed::doctor(['morph_keys'])->has('morph_keys.incompatible'))->toBeTrue();
});

it('accepts nonincrementing integer keys', function () {
    config()->set('storyfeed.discovery.paths', []);
    Storyfeed::feedable(MorphIntegerModel::class);
    expect(Storyfeed::doctor(['morph_keys'])->findings)->toBeEmpty();
});

it('checks recorded role aliases without requiring Feedable registration', function () {
    config()->set('storyfeed.discovery.paths', []);
    Relation::morphMap(['string_role' => MorphStringModel::class]);
    DB::table('feed_activities')->insert([
        'uid' => (string) Str::ulid(), 'verb' => 'note', 'target_type' => 'string_role', 'target_id' => 1,
    ]);
    $finding = Storyfeed::doctor(['morph_keys'])->withCode('morph_keys.incompatible')->sole();
    expect($finding->subject['model'])->toBe(MorphStringModel::class);
});

class MorphIntegerModel extends Model
{
    public $incrementing = false;
}

class MorphStringModel extends Model
{
    protected $keyType = 'string';

    public $incrementing = false;
}
