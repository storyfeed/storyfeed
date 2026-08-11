<?php

use Storyfeed\Concerns\AsFeedVerb;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\PendingActivity;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('records from an enum case', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    $activity = ActivityVerb::Confirm
        ->actor($user)
        ->object($delivery)
        ->in($customer)
        ->publish();

    expect($activity->verb)->toBe('confirm')
        ->and($activity->actor_id)->toEqual($user->id)
        ->and($activity->object_type)->toBe('delivery')
        ->and($activity->target_type)->toBe('customer');
});

it('publishes directly from an enum case', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    $activity = ActivityVerb::Confirm->publish($delivery);

    expect($activity->verb)->toBe('confirm')
        ->and($activity->object_id)->toEqual($delivery->id);
});

it('records with named arguments from an enum case', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    $activity = ActivityVerb::Upload->record(
        object: $delivery,
        actor: $user,
        target: $customer,
        data: ['size' => 1024],
    );

    expect($activity->verb)->toBe('upload')
        ->and($activity->refresh()->data)->toBe(['size' => 1024])
        ->and($activity->target_id)->toEqual($customer->id);
});

it('produces identical rows and grouping hashes however the verb was expressed', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    $viaString = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'A']))
        ->actor($user)->publish();

    $viaEnum = Storyfeed::activity(ActivityVerb::Confirm, Delivery::create(['tracking_number' => 'B']))
        ->actor($user)->publish();

    $viaKickstart = ActivityVerb::Confirm
        ->actor($user)->object(Delivery::create(['tracking_number' => 'C']))->publish();

    expect($viaEnum->verb)->toBe($viaString->verb)
        ->and($viaKickstart->verb)->toBe($viaString->verb);

    // All three land in the same repeat group.
    $hashes = Grouping::query()
        ->whereIn('activity_id', [$viaString->id, $viaEnum->id, $viaKickstart->id])
        ->where('bucket', 'repeat')
        ->pluck('hash')
        ->unique();

    expect($hashes)->toHaveCount(1);
});

it('carries the AS2.0 mapping declared on the enum', function () {
    expect(ActivityVerb::Confirm->activityType()->value)->toBe('Update')
        ->and(ActivityVerb::Upload->activityType()->value)->toBe('Add')
        ->and(ActivityVerb::Confirm->verb())->toBe('confirm');
});

it('forwards every chainable builder method', function () {
    $builder = new ReflectionClass(PendingActivity::class);
    $trait = new ReflectionClass(AsFeedVerb::class);

    $chainable = [];

    foreach ($builder->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== PendingActivity::class) {
            continue; // Conditionable's when()/unless()
        }

        if ($method->isStatic() || $method->getName() === 'verb') {
            continue; // verb() belongs to the FeedVerb contract
        }

        if ((string) $method->getReturnType() === 'static') {
            $chainable[] = $method->getName();
        }
    }

    $missing = array_diff($chainable, array_map(
        fn (ReflectionMethod $m) => $m->getName(),
        $trait->getMethods(ReflectionMethod::IS_PUBLIC),
    ));

    expect($chainable)->not->toBeEmpty()
        ->and($missing)->toBe([], 'AsFeedVerb is missing forwards for: '.implode(', ', $missing));
});

it('never records anything from a bare enum case', function () {
    ActivityVerb::Confirm->actor(User::create(['name' => 'S', 'email' => 's@example.com']));

    expect(Activity::query()->count())->toBe(0);
});
