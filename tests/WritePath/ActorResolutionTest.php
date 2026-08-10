<?php

use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\User;

it('defaults the actor to the authenticated user', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    $this->actingAs($user);

    $activity = Storyfeed::activity()->verb('ping')->publish();

    expect($activity->actor_type)->toBe('user')
        ->and($activity->actor_id)->toEqual($user->id);
});

it('publishes anonymously when no actor is resolvable', function () {
    $activity = Storyfeed::activity()->verb('ping')->publish();

    expect($activity->actor_type)->toBeNull()
        ->and($activity->actor_id)->toBeNull();
});

it('uses a custom actor resolver', function () {
    $user = User::create(['name' => 'System Bot', 'email' => 'bot@example.com']);

    Storyfeed::resolveActorUsing(fn () => $user);

    $activity = Storyfeed::activity()->verb('ping')->publish();

    expect($activity->actor_id)->toEqual($user->id);
});

it('lets an explicit actor win over the resolver', function () {
    $authed = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $other = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $this->actingAs($authed);

    $activity = Storyfeed::activity()->actor($other)->verb('ping')->publish();

    expect($activity->actor_id)->toEqual($other->id);
});
