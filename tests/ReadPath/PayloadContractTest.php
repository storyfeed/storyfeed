<?php

use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('emits the same payload shape as before the recording API change', function () {
    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity('confirm', $delivery)->actor($user)->for($customer)->publish();

    $payload = Storyfeed::feed()->get()->toArray();

    expect(array_keys($payload))->toBe(['payload_version', 'items', 'next_cursor']);
    expect(array_keys($payload['items'][0]))->toBe([
        'kind', 'id', 'verb', 'published_at', 'headline_template', 'headline',
        'icon', 'actor', 'object', 'target', 'context', 'data',
    ]);
    expect(array_keys($payload['items'][0]['object']))->toBe([
        'type', 'id', 'label', 'url', 'attributes', 'modal', 'component', 'data',
    ]);
});

it('emits the frozen group-node shape', function () {
    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    foreach (range(1, 2) as $i) {
        Storyfeed::activity()
            ->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))
            ->publish();
    }

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['kind'])->toBe('group');
    expect(array_keys($item))->toBe([
        'kind', 'id', 'axis', 'count', 'verb', 'published_at', 'headline_template',
        'headline', 'icon', 'exemplars', 'others_count', 'children', 'children_truncated',
    ]);
    // 'object' added 2026-08-12 (additive, pre-1.0): non-null only on the
    // object axis, the one axis that pins object identity.
    expect(array_keys($item['exemplars']))->toBe(['actors', 'object', 'target', 'context']);
    expect(array_keys($item['children'][0]))->toBe([
        'kind', 'id', 'verb', 'published_at', 'headline_template', 'headline',
        'icon', 'actor', 'object', 'target', 'context', 'data',
    ]);
});
