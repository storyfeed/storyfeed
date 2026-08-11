<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('emits fully self-describing entities with fresh links', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['customer_id' => $customer->id, 'tracking_number' => 'TN-1042', 'status' => 'confirmed']);

    Storyfeed::activity()->actor($user)->verb('confirm', $delivery)->for($customer)->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['id'])->toHaveLength(26)
        ->and($item['object'])->toMatchArray([
            'type' => 'delivery',
            'id' => (string) $delivery->id,
            'label' => 'Delivery #TN-1042',
            'url' => "/deliveries/{$delivery->id}",
            'component' => 'Resource',
        ])
        ->and($item['object']['data']['status'])->toBe('confirmed')
        ->and($item['actor']['url'])->toBe("/users/{$user->id}")
        ->and($item['target']['label'])->toBe('Acme Co.');
});

it('degrades gracefully instead of hiding activities with missing snapshots', function () {
    // Simulate a legacy/backfill-pending row: raw create, no snapshots.
    Activity::query()->create([
        'verb' => 'confirm',
        'object_type' => 'delivery',
        'object_id' => 999,
        'published_at' => now(),
    ]);

    $items = Storyfeed::feed()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['object']['label'])->toBeNull()
        ->and($items[0]['object']['type'])->toBe('delivery')
        ->and($items[0]['object']['id'])->toBe('999');
});

it('regenerates links from cached data at read time', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1', 'status' => 'draft']);

    Storyfeed::activity('confirm', $delivery)->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['object']['attributes'])->toMatchArray(['data-status' => 'draft']);
});
