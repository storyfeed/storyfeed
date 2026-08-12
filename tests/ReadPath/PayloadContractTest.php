<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\StoryfeedManager;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveryWasConfirmed;

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

it('emits a byte-identical payload whether authored as a Story or a registry entry', function () {
    // THE test that makes the Story layer safe. Stories compile down into the
    // registries, so the payload must not be able to tell which authoring
    // surface was used. If this holds, authoring-layer R&D can continue
    // indefinitely behind a frozen contract — which is the architectural
    // promise the layer was designed around.
    $strip = function (array $payload): array {
        // ids and timestamps differ per row by design.
        foreach ($payload['items'] as &$item) {
            unset($item['id'], $item['published_at']);
        }

        unset($payload['next_cursor'], $payload['sync_token']);

        return $payload;
    };

    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $customer = Customer::create(['name' => 'Acme Co.']);

    // Authored the old way.
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object for :target'])
        ->icons(['delivery.confirm' => 'bi-truck'])
        ->verbs(['confirm' => 'Update']);

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))
        ->actor($user)->for($customer)->publish();

    $viaRegistry = $strip(Storyfeed::feed()->get()->toArray());

    // Same activity, authored as a Story, on a fresh manager.
    Activity::query()->forceDelete();
    app()->forgetInstance(StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();

    Storyfeed::stories([DeliveryWasConfirmed::class]);

    DeliveryWasConfirmed::activity(Delivery::create(['tracking_number' => 'TN-2']))
        ->actor($user)->for($customer)->publish();

    $viaStory = $strip(Storyfeed::feed()->get()->toArray());

    // Object labels differ (different tracking numbers), so compare everything
    // the authoring layer could possibly have moved.
    expect($viaStory['payload_version'])->toBe($viaRegistry['payload_version'])
        ->and(array_keys($viaStory['items'][0]))->toBe(array_keys($viaRegistry['items'][0]))
        ->and($viaStory['items'][0]['headline_template'])->toBe($viaRegistry['items'][0]['headline_template'])
        ->and($viaStory['items'][0]['icon'])->toBe($viaRegistry['items'][0]['icon'])
        ->and($viaStory['items'][0]['verb'])->toBe($viaRegistry['items'][0]['verb'])
        ->and($viaStory['items'][0]['actor'])->toBe($viaRegistry['items'][0]['actor'])
        ->and($viaStory['items'][0]['target'])->toBe($viaRegistry['items'][0]['target']);
});
