<?php

use Illuminate\Support\Facades\Event;
use Storyfeed\Actions\WriteGroupings;
use Storyfeed\Events\BatchClosed;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Batch;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('collects an actor\'s burst of publishes into one open batch, invisibly', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($sally)->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))->publish();
    }

    $batch = Batch::query()->sole();

    expect($batch->isOpen())->toBeTrue()
        ->and($batch->activities_count)->toBe(3)
        ->and($batch->last_activity_at)->not->toBeNull()
        ->and($batch->activities()->count())->toBe(3)
        ->and($batch->actor_type)->toBe('user');
});

it('gives different actors separate concurrent batches', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

    Storyfeed::activity()->actor($sally)->verb('ping')->publish();
    Storyfeed::activity()->actor($bob)->verb('ping')->publish();
    Storyfeed::activity()->actor($sally)->verb('ping')->publish();

    expect(Batch::query()->count())->toBe(2)
        ->and(Batch::query()->forActor($sally)->sole()->activities_count)->toBe(2)
        ->and(Batch::query()->forActor($bob)->sole()->activities_count)->toBe(1);
});

it('never batches anonymous activities', function () {
    Storyfeed::activity()->verb('ping')->publish();

    expect(Batch::query()->count())->toBe(0);
});

it('batches a named party actor like any other', function () {
    Storyfeed::activity()->actor('Concur')->verb('sync')->publish();

    expect(Batch::query()->sole()->activities_count)->toBe(1);
});

it('closes a stale batch lazily on the actor\'s next publish', function () {
    Event::fake([BatchClosed::class]);

    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    Storyfeed::activity()->actor($sally)->verb('ping')->publish();

    $this->travel(11)->minutes();

    Storyfeed::activity()->actor($sally)->verb('ping')->publish();

    $batches = Batch::query()->orderBy('id')->get();

    expect($batches)->toHaveCount(2)
        ->and($batches[0]->isOpen())->toBeFalse()
        ->and($batches[1]->isOpen())->toBeTrue();

    Event::assertDispatchedTimes(BatchClosed::class, 1);
});

it('keeps the batch open while activity continues inside the window', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    // Each publish is 5 minutes apart — always inside the 10-minute quiet
    // window, so the SLIDING window keeps one batch open for 20 minutes.
    foreach (range(1, 5) as $i) {
        Storyfeed::activity()->actor($sally)->verb('ping')->publish();
        $this->travel(5)->minutes();
    }

    expect(Batch::query()->count())->toBe(1)
        ->and(Batch::query()->sole()->activities_count)->toBe(5);
});

it('sweeps quiet batches closed with storyfeed:close-batches', function () {
    Event::fake([BatchClosed::class]);

    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    Storyfeed::activity()->actor($sally)->verb('ping')->publish();

    $this->travel(11)->minutes();

    $this->artisan('storyfeed:close-batches')->assertSuccessful();

    expect(Batch::query()->sole()->isOpen())->toBeFalse();

    Event::assertDispatchedTimes(BatchClosed::class, 1);
});

it('closes an empty stale batch without firing the event', function () {
    Event::fake([BatchClosed::class]);

    Batch::query()->create([
        'actor_type' => 'user',
        'actor_id' => 999,
        'opened_at' => now()->subHour(),
    ]);

    $this->artisan('storyfeed:close-batches')->assertSuccessful();

    expect(Batch::query()->sole()->isOpen())->toBeFalse();

    Event::assertNotDispatched(BatchClosed::class);
});

it('writes nothing when batching is disabled', function () {
    config()->set('storyfeed.grouping.batch.enabled', false);

    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    Storyfeed::activity()->actor($sally)->verb('ping')->publish();

    expect(Batch::query()->count())->toBe(0)
        ->and(Grouping::query()->where('bucket', 'batch')->count())->toBe(0);
});

it('survives WriteGroupings re-runs — the trickle must not destroy batch membership', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    $activity = Storyfeed::activity()->actor($sally)->verb('ping')->publish();

    expect(Grouping::query()->where('activity_id', $activity->id)->where('bucket', 'batch')->count())->toBe(1);

    // The trickle re-runs WriteGroupings over legacy rows; its stale-bucket
    // delete must exempt batch rows, which the strategy never emits.
    (new WriteGroupings)($activity);

    expect(Grouping::query()->where('activity_id', $activity->id)->where('bucket', 'batch')->count())->toBe(1);
});

it('keeps batches out of curation and out of every feed', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    // Mixed verbs in one burst: one batch, but the batch must not become a
    // feed group — the composite/session question is explicitly unsettled.
    Storyfeed::activity()->actor($sally)->verb('create', Delivery::create(['tracking_number' => 'TN-1']))->publish();
    Storyfeed::activity()->actor($sally)->verb('ping')->publish();

    expect(Grouping::query()->where('bucket', 'batch')->whereNotNull('winner')->count())->toBe(0);

    foreach ([Storyfeed::feed(), Storyfeed::feed()->live(), Storyfeed::feed()->log()] as $feed) {
        $axes = collect($feed->get()->toArray()['items'])->pluck('axis')->filter();

        expect($axes->all())->not->toContain('batch');
    }
});
