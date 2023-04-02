<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\FeedActivity;
use Storyfeed\Prototype\Models\Delivery;
use Storyfeed\Prototype\Models\User;
use Storyfeed\Support\ActivityBuilder;

it('can start building an activity', function () {
    $builder = ActivityBuilder::make();

    expect($builder->activity)->toBeInstanceOf(FeedActivity::class);
});

it('can publish an activity', function () {
    $user = User::factory()->create();
    $delivery = Delivery::factory()->create();
    $customer = $delivery->customer;

    $activity = ActivityBuilder::make()
        ->actor($user)
        ->type('create')
        ->object($delivery)
        ->target($customer)
        ->on($delivery->created_at)
        ->publish();

    expect($activity->exists)->toBeTrue();
    expect($activity)->toBeInstanceOf(FeedActivity::class);
    expect($activity->actor_id)->toBe($user->id);
    expect($activity->type)->toBe('create');
    expect($activity->published_at)->toEqual($delivery->created_at);
    expect($activity->object_type)->toBe($delivery->getMorphClass());
    expect($activity->object_id)->toBe($delivery->id);
    expect($activity->target_type)->toBe($customer->getMorphClass());
    expect($activity->target_id)->toBe($customer->id);
});

it('can publish an activity with fluent syntax', function () {
    $user = User::factory()->create();
    $delivery = Delivery::factory()->create();
    $customer = $delivery->customer;

    $activity = Storyfeed::activity()
        ->actor($user)
        ->create($delivery)
        ->for($customer)
        ->on($delivery->created_at)
        ->publish();

    expect($activity->exists)->toBeTrue();
    expect($activity)->toBeInstanceOf(FeedActivity::class);
    expect($activity->actor_id)->toBe($user->id);
    expect($activity->type)->toBe('create');
    expect($activity->published_at)->toEqual($delivery->created_at);
    expect($activity->object_type)->toBe($delivery->getMorphClass());
    expect($activity->object_id)->toBe($delivery->id);
    expect($activity->target_type)->toBe($customer->getMorphClass());
    expect($activity->target_id)->toBe($customer->id);
});
