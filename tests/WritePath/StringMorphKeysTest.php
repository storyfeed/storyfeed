<?php

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\PurgeActivities;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Party;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Tests\Fixtures\StringKeyMigrations;
use Workbench\App\Models\Courier;

uses(StringKeyMigrations::class);

it('migrates every app model id column as text while retaining package numeric keys', function () {
    $columns = [
        'feed_activities' => array_map(fn ($role) => $role.'_id', ActivityRoles::STORED),
        'feed_batches' => ['actor_id'], 'feed_snapshots' => ['model_id'],
        'feed_participants' => ['entity_id'], 'feed_batch_locks' => ['actor_id'],
        'feed_tombstones' => ['model_id'],
    ];
    foreach ($columns as $table => $names) {
        foreach ($names as $column) {
            expect(Schema::getColumnType($table, $column))->toBe('varchar', "$table.$column");
        }
    }
    expect(Schema::getColumnType('feed_tombstones', 'id'))->toContain('int')
        ->and(Schema::getColumnType('feed_parties', 'id'))->toContain('int')
        ->and(Storyfeed::doctor(['morph_keys'])->findings)->toBeEmpty();
});

it('snapshots numeric parties and preserves referenced tombstones while purging string roles', function () {
    $party = Party::make('System');
    $courier = Courier::create(['name' => 'Ada']);
    $first = Storyfeed::activity('note', $courier)->by($party)->publish();
    $second = Storyfeed::activity('note', $courier)->by($party)->publish();
    $courier->delete();
    $tombstone = FeedTombstone::sole();
    expect($first->fresh()->object_type)->toBe($tombstone->getMorphClass());

    // This exercises Check::objectTypeOf's package-PK join.
    expect(Storyfeed::doctor(['removals'])->has('doctor.check_failed'))->toBeFalse();
    $purge = new PurgeActivities;
    $purge(fn () => Activity::query()->whereKey($first->id));
    expect(FeedTombstone::query()->whereKey($tombstone->id)->exists())->toBeTrue();
    $purge(fn () => Activity::query()->whereKey($second->id));
    expect(FeedTombstone::query()->whereKey($tombstone->id)->exists())->toBeFalse();
});

it('stores UUID app keys alongside package numeric parties', function () {
    Schema::create('uuid_key_probes', function (Blueprint $table) {
        $table->uuid('id')->primary();
    });
    Relation::morphMap(['uuid_probe' => UuidKeyProbe::class]);
    Storyfeed::feedable(UuidKeyProbe::class)->toFeedUsing(fn ($model, $entity) => $entity->label('UUID model'));
    $model = UuidKeyProbe::create();
    $party = Party::make('System');
    $activity = Storyfeed::activity('note', $model)->by($party)->publish();
    expect($activity->fresh()->object_id)->toBe($model->id)
        ->and($activity->fresh()->actor_id)->toBe((string) $party->id)
        ->and(Storyfeed::doctor(['morph_keys'])->findings)->toBeEmpty();
});

class UuidKeyProbe extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];
}

it('tombstones a non-model Feedable by its alias', function () {
    Storyfeed::tombstone('remote.invoice', 'INV-7');

    $tombstone = FeedTombstone::sole();

    expect($tombstone->model_type)->toBe('remote.invoice')
        ->and($tombstone->model_id)->toBe('INV-7')
        ->and($tombstone->restorable)->toBeFalse();
});
