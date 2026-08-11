<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Snapshot;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('snapshots every feedable entity synchronously on publish', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['customer_id' => $customer->id, 'tracking_number' => 'TN-1042']);

    $activity = Storyfeed::activity()
        ->actor($user)
        ->verb('confirm', $delivery)
        ->for($customer)
        ->publish();

    expect($activity->cached_actor_id)->not->toBeNull()
        ->and($activity->cached_object_id)->not->toBeNull()
        ->and($activity->cached_target_id)->not->toBeNull();

    expect($activity->cachedObject->label)->toBe('Delivery #TN-1042')
        ->and($activity->cachedObject->component)->toBe('Resource')
        ->and($activity->cachedObject->model_type)->toBe('delivery')
        ->and($activity->cachedActor->label)->toBe('Sally')
        ->and($activity->cachedTarget->label)->toBe('Acme Co.');
});

it('keeps one snapshot row per entity', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity('create', $delivery)->publish();
    Storyfeed::activity('save', $delivery)->publish();

    expect(Snapshot::query()->where('model_type', 'delivery')->count())->toBe(1);
});

it('refreshes the snapshot when the model is saved', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1', 'status' => 'draft']);

    Storyfeed::activity('create', $delivery)->publish();

    $delivery->update(['status' => 'shipped']);

    $snapshot = Snapshot::query()
        ->where('model_type', 'delivery')
        ->where('model_id', $delivery->id)
        ->first();

    expect($snapshot->data['status'])->toBe('shipped');
});
