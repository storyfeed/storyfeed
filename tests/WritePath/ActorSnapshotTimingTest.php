<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('snapshots a resolver-supplied actor at publish, not on the next trickle', function () {
    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    $this->actingAs($user);

    $activity = Storyfeed::activity('ping')->publish();

    // The actor came from resolveActor(), not an explicit ->actor() call.
    expect($activity->actor_id)->toEqual($user->id)
        ->and($activity->cached_actor_id)->not->toBeNull()
        ->and($activity->cachedActor->label)->toBe('Sally');
});

it('snapshots a fallback party at publish', function () {
    config()->set('storyfeed.parties.fallback', 'System');

    $activity = Storyfeed::activity('ping')->publish();

    expect($activity->actor_type)->toBe('storyfeed.party')
        ->and($activity->cached_actor_id)->not->toBeNull()
        ->and($activity->cachedActor->label)->toBe('System');
});

it('stays anonymous when no fallback is configured', function () {
    $activity = Storyfeed::activity('ping')->publish();

    expect($activity->actor_type)->toBeNull()
        ->and(Party::query()->count())->toBe(0);
});

it('lets an explicit actor beat the fallback', function () {
    config()->set('storyfeed.parties.fallback', 'System');

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    $activity = Storyfeed::activity('ping')->actor($user)->publish();

    expect($activity->actor_type)->toBe('user');
});

it('still resolves an actor for activities created without the builder', function () {
    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    $this->actingAs($user);

    // The model hook remains as a fallback for direct creates.
    $activity = Activity::query()->create(['verb' => 'ping', 'published_at' => now()]);

    expect($activity->actor_id)->toEqual($user->id);
});

it('survives the trickle when the app enforces a morph map without our alias', function () {
    $activity = Storyfeed::activity('sync', Delivery::create(['tracking_number' => 'TN-1']))
        ->actor('Concur')
        ->publish();

    // Force the trickle to actually process this row: only uncached
    // activities are examined, and publish() snapshots synchronously.
    $activity->forceFill(['cached_actor_id' => null])->save();

    // An app-owned enforced map that knows nothing about package models.
    // Without MorphResolver's package fallback, TrickleSnapshots would treat
    // the party as an orphan and soft-delete the activity.
    Relation::enforceMorphMap([
        'user' => User::class,
        'delivery' => Delivery::class,
    ]);

    $this->artisan('storyfeed:trickle')->assertSuccessful();

    expect(Activity::query()->count())->toBe(1)
        ->and($activity->refresh()->cached_actor_id)->not->toBeNull();
});
