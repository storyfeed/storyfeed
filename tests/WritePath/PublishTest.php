<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('publishes an activity with the fluent builder', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['customer_id' => $customer->id, 'tracking_number' => 'TN-1042']);

    $activity = Storyfeed::activity()
        ->actor($user)
        ->confirm($delivery)
        ->for($customer)
        ->publish();

    expect($activity)->toBeInstanceOf(Activity::class)
        ->and($activity->exists)->toBeTrue()
        ->and($activity->verb)->toBe('confirm')
        ->and($activity->actor_type)->toBe('user')
        ->and($activity->actor_id)->toEqual($user->id)
        ->and($activity->object_type)->toBe('delivery')
        ->and($activity->object_id)->toEqual($delivery->id)
        ->and($activity->target_type)->toBe('customer')
        ->and($activity->target_id)->toEqual($customer->id)
        ->and($activity->published_at)->not->toBeNull();
});

it('assigns a public ulid uid on publish', function () {
    $activity = Storyfeed::activity()->verb('ping')->publish();

    expect($activity->uid)->toBeString()->toHaveLength(26)
        ->and($activity->id)->toBeInt();
});

it('maps unknown builder methods to the verb', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    $activity = Storyfeed::activity()->dispatch($delivery)->publish();

    expect($activity->verb)->toBe('dispatch')
        ->and($activity->object_type)->toBe('delivery');
});

it('records the context role', function () {
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['tracking_number' => 'TN-2']);

    $activity = Storyfeed::activity()
        ->verb('create')
        ->object($delivery)
        ->context($customer)
        ->publish();

    expect($activity->context_type)->toBe('customer')
        ->and($activity->context_id)->toEqual($customer->id);
});

it('accepts an explicit publish date', function () {
    $date = now()->subDays(3)->startOfSecond();

    $activity = Storyfeed::activity()->verb('ping')->publishedAt($date)->publish();

    expect($activity->published_at->equalTo($date))->toBeTrue();
});

it('stores activity data', function () {
    $activity = Storyfeed::activity()
        ->verb('status')
        ->data(['from' => 'draft', 'to' => 'shipped'])
        ->publish();

    expect($activity->refresh()->data)->toBe(['from' => 'draft', 'to' => 'shipped']);
});
