<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

it('soft-deletes activities involving a deleted model', function () {
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['customer_id' => $customer->id, 'tracking_number' => 'TN-1']);

    Storyfeed::activity()->confirm($delivery)->for($customer)->publish();
    Storyfeed::activity()->verb('ping')->publish();

    $delivery->delete();

    expect(Activity::query()->count())->toBe(1)
        ->and(Activity::query()->withTrashed()->count())->toBe(2);
});

it('force-deletes activities when the model is force-deleted', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity()->confirm($delivery)->publish();

    $delivery->forceDelete();

    expect(Activity::query()->withTrashed()->count())->toBe(0);
});

it('force-deletes even activities that were already soft-deleted', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity()->confirm($delivery)->publish();

    $delivery->delete();      // soft: activities soft-deleted
    $delivery->forceDelete(); // hard: trashed activities purged

    expect(Activity::query()->withTrashed()->count())->toBe(0);
});
