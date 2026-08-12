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

    // 'sync_token' added 2026-08-12 (additive): opaque, cursor-grained —
    // store it; when a later page's differs, drop accumulated nodes and
    // refetch. Null until the first settled-history rewrite ever.
    expect(array_keys($payload))->toBe(['payload_version', 'items', 'next_cursor', 'sync_token']);
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
        'headline', 'icon', 'exemplars', 'distinct', 'children', 'children_truncated',
    ]);
    // UNIFORM exemplars (2026-08-12, deliberate pre-freeze break): every
    // role is a LIST of up to 3 distinct entities; a pinned role collapses
    // to exactly one by construction. `distinct` carries true per-role
    // totals (replacing others_count).
    expect(array_keys($item['exemplars']))->toBe(['actors', 'objects', 'targets', 'contexts']);
    expect(array_keys($item['distinct']))->toBe(['actors', 'objects', 'targets', 'contexts']);
    expect(array_keys($item['children'][0]))->toBe([
        'kind', 'id', 'verb', 'published_at', 'headline_template', 'headline',
        'icon', 'actor', 'object', 'target', 'context', 'data',
    ]);
});
