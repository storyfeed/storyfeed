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
