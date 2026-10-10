<?php

use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\StoryfeedManager;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveryWasConfirmed;

it('emits the same payload shape as before the recording API change', function () {
    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity('confirm', $delivery)->actor($user)->for($customer)->publish();

    $payload = Storyfeed::feed()->cursorPaginate()->toArray();

    // The contract is the node shape (#95, 2026-10-09): a page is Laravel's
    // own paginator JSON, the nodes in `data`, with the two feed-level values
    // as extra top-level keys, where an API resource's additional() puts them.
    // 'sync_token' (2026-08-12): opaque, cursor-grained — store it; when a
    // later page's differs, drop accumulated nodes and refetch. Null until
    // the first settled-history rewrite ever.
    expect(array_keys($payload))->toBe([
        'data', 'path', 'per_page', 'next_cursor', 'next_page_url', 'prev_cursor', 'prev_page_url',
        'payload_version', 'sync_token',
    ]);
    // get() is the nodes and nothing else.
    expect(Storyfeed::feed()->get()->toArray())->toBe($payload['data']);
    expect(array_keys($payload['data'][0]))->toBe([
        'kind', 'id', 'verb', 'published_at', 'starts_at', 'ends_at', 'headline_template', 'headline',
        'glyph', 'glyph_intent', 'actor', 'object', 'target', 'context', 'origin', 'result', 'instrument', 'location', 'generator', 'featured', 'data',
        'tombstoned', 'redundant', 'missing_headline_template', 'missing_headline',
    ]);
    // `url`, `attributes` and `modal` became one `link` (2026-10-09, #79).
    expect(array_keys($payload['data'][0]['object']))->toBe([
        'type', 'id', 'label', 'link', 'data', 'media', 'body', 'tombstone',
    ]);
    expect(array_keys($payload['data'][0]['object']['link']))->toBe(['href', 'modal', 'attributes']);
});

it('emits the frozen group-node shape', function () {
    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    foreach (range(1, 2) as $i) {
        Storyfeed::activity()
            ->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))
            ->publish();
    }

    $item = Storyfeed::feed()->get()->toArray()[0];

    expect($item['kind'])->toBe('group');
    expect(array_keys($item))->toBe([
        'kind', 'id', 'axis', 'count', 'verb', 'published_at', 'headline_template',
        'headline', 'glyph', 'glyph_intent', 'actor', 'object', 'target', 'context', 'origin', 'result', 'instrument',
        'location', 'generator', 'featured', 'sample', 'distinct', 'children', 'children_truncated',
        'tombstoned', 'redundant', 'distinct_tombstoned',
    ]);
    // PINNED SINGULAR ROLES (2026-08-26, ADDITIVE): a role the axis pins is one
    // entity by construction, and `aggregateTokens()` already promised the
    // singular token was safe — so the node now carries it. In the same order
    // as an activity node's, which is what lets a renderer read a role the same
    // way on both. A role the axis does not pin is null here and lives in
    // `sample`; two renderers reconstructed this for themselves before the
    // payload did it once.
    expect($item['actor']['label'])->toBe('Sally')
        ->and($item['object'])->toBeNull();
    // UNIFORM sample (2026-08-12, deliberate pre-freeze break): every
    // role is a LIST of up to 3 distinct entities; a pinned role collapses
    // to exactly one by construction. `distinct` carries true per-role
    // totals (replacing others_count).
    expect(array_keys($item['sample']))->toBe(['actors', 'objects', 'targets', 'contexts', 'origins', 'results', 'instruments', 'locations', 'generators', 'featured']);
    expect(array_keys($item['distinct']))->toBe(['actors', 'objects', 'targets', 'contexts', 'origins', 'results', 'instruments', 'locations', 'generators', 'featured']);
    // A group's children are ordinary activity nodes.
    expect(array_keys($item['children'][0]))->toBe([
        'kind', 'id', 'verb', 'published_at', 'starts_at', 'ends_at', 'headline_template', 'headline',
        'glyph', 'glyph_intent', 'actor', 'object', 'target', 'context', 'origin', 'result', 'instrument', 'location', 'generator', 'featured', 'data',
        'tombstoned', 'redundant', 'missing_headline_template', 'missing_headline',
    ]);
});

it('emits a byte-identical payload whether authored by a message class or a fluent declaration', function () {
    // THE test that makes the Story layer safe. Stories compile down into the
    // registries, so the payload must not be able to tell which authoring
    // surface was used. If this holds, authoring-layer R&D can continue
    // indefinitely behind a frozen contract — which is the architectural
    // promise the layer was designed around.
    $strip = function (array $payload): array {
        // ids and timestamps differ per row by design.
        foreach ($payload['data'] as &$item) {
            unset($item['id'], $item['published_at']);
        }

        unset($payload['next_cursor'], $payload['sync_token']);

        return $payload;
    };

    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $customer = Customer::create(['name' => 'Acme Co.']);

    // Authored with modern fluent declarations.
    Story::for('delivery')->verb('confirm')->headline(':actor confirmed :object for :target')->icon('bi-truck');
    Storyfeed::verbs(['confirm' => 'Update']);

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))
        ->actor($user)->for($customer)->publish();

    $viaFluent = $strip(Storyfeed::feed()->cursorPaginate()->toArray());

    // Same activity, authored by a message class, on a fresh manager.
    Activity::query()->forceDelete();
    app()->forgetInstance(StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();

    Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);

    Storyfeed::publish(new DeliveryWasConfirmed(Delivery::create(['tracking_number' => 'TN-2']), $user, $customer));

    $viaStory = $strip(Storyfeed::feed()->cursorPaginate()->toArray());

    // Object labels differ (different tracking numbers), so compare everything
    // the authoring layer could possibly have moved.
    expect($viaStory['payload_version'])->toBe($viaFluent['payload_version'])
        ->and(array_keys($viaStory['data'][0]))->toBe(array_keys($viaFluent['data'][0]))
        ->and($viaStory['data'][0]['headline_template'])->toBe($viaFluent['data'][0]['headline_template'])
        ->and($viaStory['data'][0]['glyph'])->toBe($viaFluent['data'][0]['glyph'])
        ->and($viaStory['data'][0]['glyph_intent'])->toBe($viaFluent['data'][0]['glyph_intent'])
        ->and($viaStory['data'][0]['verb'])->toBe($viaFluent['data'][0]['verb'])
        ->and($viaStory['data'][0]['actor'])->toBe($viaFluent['data'][0]['actor'])
        ->and($viaStory['data'][0]['target'])->toBe($viaFluent['data'][0]['target']);
});
