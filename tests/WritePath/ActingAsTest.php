<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('attributes everything published inside the scope', function () {
    Storyfeed::as('System', function () {
        Storyfeed::activity('sync', Delivery::create(['tracking_number' => 'A']))->publish();
        Storyfeed::activity('sync', Delivery::create(['tracking_number' => 'B']))->publish();
    });

    $activities = Activity::query()->get();

    expect($activities)->toHaveCount(2)
        ->and($activities->pluck('actor_type')->unique()->all())->toBe(['storyfeed.party'])
        ->and(Party::query()->where('key', 'system')->exists())->toBeTrue();
});

it('snapshots the scoped actor synchronously', function () {
    $activity = Storyfeed::as('System', fn () => Storyfeed::activity('ping')->publish());

    expect($activity->cached_actor_id)->not->toBeNull()
        ->and($activity->cachedActor->label)->toBe('System');
});

it('returns whatever the callback returns', function () {
    $result = Storyfeed::as('System', fn () => 'done');

    expect($result)->toBe('done');
});

it('accepts a model as well as a name', function () {
    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    $activity = Storyfeed::as($user, fn () => Storyfeed::activity('ping')->publish());

    expect($activity->actor_type)->toBe('user')
        ->and($activity->actor_id)->toEqual($user->id);
});

it('restores the previous resolver afterwards', function () {
    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);
    $this->actingAs($user);

    Storyfeed::as('System', fn () => Storyfeed::activity('ping')->publish());

    $after = Storyfeed::activity('ping')->publish();

    expect($after->actor_type)->toBe('user');
});

it('restores the previous resolver even when the callback throws', function () {
    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);
    $this->actingAs($user);

    try {
        Storyfeed::as('System', fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
        // expected
    }

    expect(Storyfeed::activity('ping')->publish()->actor_type)->toBe('user');
});

it('nests, restoring the outer scope', function () {
    Storyfeed::as('Outer', function () {
        Storyfeed::as('Inner', fn () => Storyfeed::activity('ping')->publish());

        $outer = Storyfeed::activity('ping')->publish();

        expect($outer->cachedActor->label)->toBe('Outer');
    });
});

it('lets an explicit actor win inside the scope', function () {
    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    $activity = Storyfeed::as('System', fn () => Storyfeed::activity('ping')->actor($user)->publish());

    expect($activity->actor_type)->toBe('user');
});

it('seeds a builder when called without a callback', function () {
    $activity = Storyfeed::as('System')->verb('sync')->object(Delivery::create(['tracking_number' => 'A']))->publish();

    expect($activity->actor_type)->toBe('storyfeed.party')
        ->and($activity->cachedActor->label)->toBe('System');
});
