<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Actions\TrickleSnapshots;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\MorphResolver;
use Storyfeed\Support\SyncToken;
use Storyfeed\Tests\Fixtures\Models\Dish;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * Everything a delete or a restore can move, as plain rows, so a round trip
 * can be compared exactly.
 *
 * @return array<string, mixed>
 */
function feedState(): array
{
    $roles = [];

    foreach (ActivityRoles::STORED as $role) {
        $roles[] = "{$role}_type";
        $roles[] = "{$role}_id";
    }

    return [
        'roles' => DB::table('feed_activities')->orderBy('id')->get(['id', ...$roles])->map(fn ($row) => (array) $row)->all(),
        'participants' => DB::table(SyncParticipants::table())->orderBy('activity_id')->orderBy('role')
            ->get(['activity_id', 'role', 'entity_type', 'entity_id'])->map(fn ($row) => (array) $row)->all(),
        'groupings' => Grouping::query()->orderBy('activity_id')->orderBy('bucket')
            ->get(['activity_id', 'bucket', 'hash', 'winner'])
            ->map(fn (Grouping $row) => [$row->activity_id, $row->bucket, $row->hash, $row->winner === null ? null : (bool) $row->winner])
            ->all(),
    ];
}

/** Every activity row still naming a model, in any role. */
function rowsNaming(string $type, int|string $id): int
{
    return Activity::query()->withTrashed()->where(function ($query) use ($type, $id) {
        foreach (ActivityRoles::STORED as $role) {
            $query->orWhere(fn ($q) => $q->where("{$role}_type", $type)->where("{$role}_id", $id));
        }
    })->count();
}

beforeEach(function () {
    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $this->sam = User::create(['name' => 'Sam', 'email' => 'sam@example.com']);
    $this->acme = Customer::create(['name' => 'Acme']);
    $this->delivery = Delivery::create(['customer_id' => $this->acme->id, 'tracking_number' => 'TN-1']);
});

it('round-trips a delete and a restore exactly', function () {
    // The delivery in every role, and twice in one row.
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->to($this->acme)->publish();
    Storyfeed::activity()->actor($this->sam)->verb('confirm', $this->delivery)->to($this->acme)->publish();
    Storyfeed::activity()->actor($this->ines)->verb('confirm', Delivery::create(['tracking_number' => 'TN-2']))->publish();
    Storyfeed::activity()->actor($this->ines)->verb('note', $this->acme)->to($this->delivery)->context($this->delivery)->publish();
    Storyfeed::activity()->actor($this->delivery)->verb('route', $this->acme)
        ->origin($this->delivery)->using($this->delivery)->resulting($this->delivery)->publish();
    $trashed = Storyfeed::activity()->actor($this->ines)->verb('dispatch', $this->delivery)->publish();
    $trashed->delete();

    $before = feedState();
    $alias = $this->delivery->getMorphClass();
    $id = $this->delivery->id;

    $this->delivery->delete();

    $tombstone = FeedTombstone::sole();

    expect(rowsNaming($alias, $id))->toBe(0)
        ->and(rowsNaming('storyfeed.tombstone', $tombstone->id))->toBe(5)
        ->and(DB::table(SyncParticipants::table())->where('entity_type', $alias)->where('entity_id', (string) $id)->count())->toBe(0)
        ->and(Snapshot::query()->where('model_type', $alias)->where('model_id', $id)->exists())->toBeFalse()
        ->and(feedState())->not->toBe($before);

    $tombstoneSnapshot = Snapshot::query()->where('model_type', 'storyfeed.tombstone')->sole();

    foreach (ActivityRoles::STORED as $role) {
        expect(Activity::query()->withTrashed()->where("{$role}_type", 'storyfeed.tombstone')
            ->where("cached_{$role}_id", '!=', $tombstoneSnapshot->id)->count())->toBe(0, $role);
    }

    $this->delivery->restore();

    $snapshot = Snapshot::query()->where('model_type', $alias)->where('model_id', $id)->sole();

    expect(feedState())->toBe($before)
        ->and(FeedTombstone::query()->count())->toBe(0)
        ->and(Snapshot::query()->where('model_type', 'storyfeed.tombstone')->count())->toBe(0);

    foreach (ActivityRoles::STORED as $role) {
        expect(Activity::query()->withTrashed()->where("{$role}_type", $alias)->where("{$role}_id", $id)
            ->where("cached_{$role}_id", '!=', $snapshot->id)->count())->toBe(0, $role);
    }
});

it('leaves no trace of the deleted model\'s label, and renders the tombstone without one', function () {
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $this->delivery->forceDelete();

    expect(Snapshot::query()->where('label', 'like', '%TN-1%')->exists())->toBeFalse()
        ->and(Storyfeed::feed()->get()->toArray()['items'][0]['object']['label'])->toBeNull();
});

it('leaves a permanent tombstone for a model without soft deletes', function () {
    $dish = Dish::create(['name' => 'Carrot Soup']);

    $activity = Storyfeed::activity()->actor($this->ines)->verb('cook', $dish)->publish();

    $dish->delete();

    $tombstone = FeedTombstone::sole();

    expect($tombstone->restorable)->toBeFalse()
        ->and($tombstone->approximate)->toBeFalse()
        ->and($tombstone->model_type)->toBe('dish')
        ->and($tombstone->model_id)->toBe((string) $dish->id)
        ->and($activity->fresh()->object_id)->toBe($tombstone->id);
});

it('stops finding a deleted model\'s history with involving(), and finds it again after a restore', function () {
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();
    Storyfeed::activity()->actor($this->sam)->verb('dispatch', $this->delivery)->publish();

    $this->delivery->delete();

    expect(Storyfeed::feed()->involving($this->delivery)->get()->items())->toBeEmpty()
        ->and(Storyfeed::feed()->involving(FeedTombstone::sole())->get()->items())->not->toBeEmpty();

    $this->delivery->restore();

    expect(Activity::query()->involving($this->delivery)->count())->toBe(2)
        ->and(Storyfeed::feed()->involving($this->delivery)->get()->items())->not->toBeEmpty();
});

it('bumps the sync token on a delete and a restore that move activities, and not otherwise', function () {
    $quiet = Delivery::create(['tracking_number' => 'TN-quiet']);
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    $token = SyncToken::current();
    $quiet->delete();

    expect(SyncToken::current())->toBe($token);

    $this->delivery->delete();
    $deleted = SyncToken::current();

    expect($deleted)->not->toBe($token);

    $this->delivery->restore();

    expect(SyncToken::current())->not->toBe($deleted);
});

it('tombstones rows after a bulk delete with Storyfeed::tombstone()', function () {
    $other = Delivery::create(['tracking_number' => 'TN-2']);
    $live = Delivery::create(['tracking_number' => 'TN-3']);
    $dish = Dish::create(['name' => 'Carrot Soup']);

    foreach ([$this->delivery, $other, $live, $dish] as $model) {
        Storyfeed::activity()->actor($this->ines)->verb('confirm', $model)->publish();
    }

    Delivery::query()->whereKey([$this->delivery->id, $other->id])->delete();
    Dish::query()->whereKey($dish->id)->delete();

    expect(FeedTombstone::query()->count())->toBe(0);

    $tombstones = Storyfeed::tombstone(Delivery::class, [$this->delivery->id, $other->id, $live->id]);
    Storyfeed::tombstone('dish', $dish->id);

    expect($tombstones)->toHaveCount(2)
        ->and(rowsNaming('delivery', $this->delivery->id))->toBe(0)
        ->and(rowsNaming('delivery', $other->id))->toBe(0)
        ->and(rowsNaming('delivery', $live->id))->toBe(1)
        ->and(rowsNaming('dish', $dish->id))->toBe(0)
        // Trashed rows know when they went; a hard-deleted one doesn't come back.
        ->and($tombstones[0]->restorable)->toBeTrue()
        ->and($tombstones[0]->deleted_at?->toDateTimeString())->toBe(Delivery::withTrashed()->find($this->delivery->id)->deleted_at->toDateTimeString())
        ->and(FeedTombstone::for('dish', $dish->id)->restorable)->toBeFalse();
});

it('tombstones a non-model Feedable by its alias', function () {
    Storyfeed::tombstone('remote.invoice', 'INV-7');

    $tombstone = FeedTombstone::sole();

    expect($tombstone->model_type)->toBe('remote.invoice')
        ->and($tombstone->model_id)->toBe('INV-7')
        ->and($tombstone->restorable)->toBeFalse();
});

it('lets the trickle find a bulk delete, and a bulk restore', function () {
    $activity = Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();
    $dish = Dish::create(['name' => 'Carrot Soup']);
    Storyfeed::activity()->actor($this->ines)->verb('cook', $dish)->publish();

    Delivery::query()->whereKey($this->delivery->id)->delete();
    Dish::query()->whereKey($dish->id)->delete();

    $result = (new TrickleSnapshots)();

    $delivery = FeedTombstone::for('delivery', $this->delivery->id);
    $gone = FeedTombstone::for('dish', $dish->id);

    expect($result['tombstoned'])->toBe(2)
        ->and($result['unresolved'])->toBe(0)
        ->and($delivery->restorable)->toBeTrue()
        ->and($delivery->approximate)->toBeFalse()
        ->and($gone->restorable)->toBeFalse()
        ->and($gone->approximate)->toBeTrue()
        ->and($activity->fresh()->object_type)->toBe('storyfeed.tombstone');

    Delivery::query()->withTrashed()->whereKey($this->delivery->id)->restore();

    $result = (new TrickleSnapshots)();

    expect($result['restored'])->toBe(1)
        ->and($activity->fresh()->object_type)->toBe('delivery')
        ->and($activity->fresh()->cachedObject->label)->toBe('Delivery #TN-1')
        ->and(FeedTombstone::for('delivery', $this->delivery->id))->toBeNull()
        ->and(FeedTombstone::for('dish', $dish->id))->not->toBeNull();
});

it('lets the trickle tombstone an uncached role whose model is gone', function () {
    DB::table('feed_activities')->insert([
        'uid' => (string) str()->ulid(),
        'verb' => 'confirm',
        'actor_type' => 'user',
        'actor_id' => $this->ines->id,
        'object_type' => 'delivery',
        'object_id' => 4242,
        'published_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = (new TrickleSnapshots)();
    $activity = Activity::sole();

    expect($result)->toMatchArray(['tombstoned' => 1, 'unresolved' => 0, 'snapshotted' => 1])
        ->and($activity->object_type)->toBe('storyfeed.tombstone')
        ->and($activity->cached_object_id)->not->toBeNull()
        ->and($activity->cached_actor_id)->not->toBeNull()
        ->and(FeedTombstone::sole()->approximate)->toBeTrue();
});

it('resolves the tombstone alias with an empty app morph map', function () {
    Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();
    $this->delivery->forceDelete();
    $tombstone = FeedTombstone::sole();

    $map = Relation::morphMap();
    Relation::morphMap([], false);

    try {
        expect(Relation::morphMap())->toBe([])
            ->and(MorphResolver::classFor('storyfeed.tombstone'))->toBe(FeedTombstone::class)
            ->and(MorphResolver::feedable('storyfeed.tombstone', $tombstone->id)?->is($tombstone))->toBeTrue();
    } finally {
        Relation::morphMap($map, false);
    }
});

it('writes no tombstone while recording is off, and leaves the deletion to the trickle', function () {
    $activity = Storyfeed::activity()->actor($this->ines)->verb('confirm', $this->delivery)->publish();

    Storyfeed::withoutRecording(fn () => $this->delivery->delete());

    expect(FeedTombstone::query()->count())->toBe(0)
        ->and($activity->fresh()->object_type)->toBe('delivery');

    (new TrickleSnapshots)();

    expect($activity->fresh()->object_type)->toBe('storyfeed.tombstone')
        ->and(FeedTombstone::sole()->approximate)->toBeFalse();
});
