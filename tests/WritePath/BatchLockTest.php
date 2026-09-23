<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\CloseBatches;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Actions\WriteGroupings;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Batch;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

// Todo 1339. The concurrency itself is proved by the opt-in probes in
// tests/Queue/ConcurrencyTest.php; these pin the bookkeeping that makes the
// batch decision lock rows by key.

function batchLock(string $type, int|string $id): ?object
{
    return DB::table('feed_batch_locks')->where('actor_type', $type)->where('actor_id', (string) $id)->first();
}

function listedBatches(string $type, int|string $id): array
{
    return json_decode(batchLock($type, $id)->open_batches, true);
}

it('keeps one lock row per actor, listing its open batch', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($sally)->verb('ping')->publish();
    }
    Storyfeed::activity()->actor('Concur')->verb('sync')->publish();

    expect(DB::table('feed_batch_locks')->count())->toBe(2)
        ->and(listedBatches('user', $sally->id))->toBe([Batch::query()->forActor($sally)->sole()->id])
        ->and(batchLock('user', $sally->id)->locked_at)->not->toBeNull();
});

it('takes no lock for an anonymous activity', function () {
    Storyfeed::activity()->verb('ping')->publish();

    expect(DB::table('feed_batch_locks')->count())->toBe(0);
});

it('drops a batch the sweeper closed and lists the one that replaces it', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    Storyfeed::activity()->actor($sally)->verb('ping')->publish();
    $first = Batch::query()->sole();

    $this->travel(11)->minutes();
    (new CloseBatches)();
    // The sweeper never touches the list, so it still names the closed batch.
    expect(listedBatches('user', $sally->id))->toBe([$first->id]);

    Storyfeed::activity()->actor($sally)->verb('ping')->publish();
    $second = Batch::query()->whereKeyNot($first->id)->sole();

    expect(listedBatches('user', $sally->id))->toBe([$second->id])
        ->and($second->activities_count)->toBe(1);
});

it('still finds an earlier open window for a late arrival, through the list', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $now = now()->toImmutable();

    Storyfeed::activity()->actor($sally)->verb('ping')->publishedAt($now)->publish();
    Storyfeed::activity()->actor($sally)->verb('ping')->publishedAt($now->subHour())->publish();
    Storyfeed::activity()->actor($sally)->verb('ping')->publishedAt($now->subHour()->addMinutes(3))->publish();

    $batches = Batch::query()->orderBy('opened_at')->get();

    expect($batches)->toHaveCount(2)
        ->and($batches->pluck('activities_count')->all())->toBe([2, 1])
        ->and($batches->every->isOpen())->toBeTrue()
        ->and(listedBatches('user', $sally->id))->toBe($batches->pluck('id')->sort()->values()->all());
});

it('lists the open batches an existing install already has when the migration runs', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $open = ['actor_type' => 'user', 'actor_id' => $sally->id, 'closed_at' => null, 'activities_count' => 1];
    DB::table('feed_batches')->insert([
        ['uid' => 'A', ...$open, 'opened_at' => now()->subMinutes(2), 'last_activity_at' => now()->subMinutes(2)],
        ['uid' => 'B', ...$open, 'opened_at' => now()->subHour(), 'last_activity_at' => now()->subHour()],
        ['uid' => 'C', ...$open, 'opened_at' => now()->subDay(), 'last_activity_at' => now()->subDay(), 'closed_at' => now()->subDay()],
        ['uid' => 'D', ...$open, 'actor_id' => $sally->id + 1, 'opened_at' => now()->subDay(), 'last_activity_at' => now()->subDay(), 'closed_at' => now()->subDay()],
    ]);
    $migration = include __DIR__.'/../../database/migrations/create_feed_batch_locks_table.php.stub';
    $migration->down();
    $migration->up();

    $ids = DB::table('feed_batches')->whereIn('uid', ['A', 'B'])->orderBy('id')->pluck('id')->all();

    expect(DB::table('feed_batch_locks')->count())->toBe(1)
        ->and(listedBatches('user', $sally->id))->toBe($ids);

    // So the first publish after the upgrade joins the window it already had.
    Storyfeed::activity()->actor($sally)->verb('ping')->publish();

    expect(Batch::query()->count())->toBe(4)
        ->and(Batch::query()->where('uid', 'A')->value('activities_count'))->toBe(2);
});

it('still rewrites groupings and participants when an activity is re-synced', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $activity = Storyfeed::activity()->actor($sally)->verb('upload', $delivery)->publish();
    $buckets = fn () => DB::table('feed_groupings')->where('activity_id', $activity->id)->orderBy('bucket')->pluck('bucket')->all();
    $before = $buckets();

    // The same instance, still "recently created", edited to drop its object.
    $activity->object()->dissociate();
    $activity->save();
    (new WriteGroupings)($activity);
    (new SyncParticipants)($activity);

    expect(DB::table('feed_participants')->where('activity_id', $activity->id)->pluck('role')->all())->toBe(['actor'])
        ->and(count($buckets()))->toBeLessThan(count($before))
        ->and($buckets())->toContain('batch');
});
