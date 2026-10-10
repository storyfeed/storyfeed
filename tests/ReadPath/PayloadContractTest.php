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

    $payload = Storyfeed::feed()->get()->toArray();

    // 'sync_token' added 2026-08-12 (additive): opaque, cursor-grained —
    // store it; when a later page's differs, drop accumulated nodes and
    // refetch. Null until the first settled-history rewrite ever.
    expect(array_keys($payload))->toBe(['payload_version', 'items', 'next_cursor', 'sync_token']);
    expect(array_keys($payload['items'][0]))->toBe([
        'kind', 'id', 'verb', 'published_at', 'starts_at', 'ends_at', 'headline_template', 'headline',
        'glyph', 'glyph_intent', 'actor', 'object', 'target', 'context', 'origin', 'result', 'instrument', 'location', 'generator', 'data',
        'tombstoned', 'redundant', 'missing_headline_template', 'missing_headline',
    ]);
    // `url`, `attributes` and `modal` became one `link` (2026-10-09, #79).
    expect(array_keys($payload['items'][0]['object']))->toBe([
        'type', 'id', 'label', 'link', 'data', 'media', 'body', 'tombstone',
    ]);
    expect(array_keys($payload['items'][0]['object']['link']))->toBe(['href', 'modal', 'attributes']);
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
        'headline', 'glyph', 'glyph_intent', 'actor', 'object', 'target', 'context', 'origin', 'result', 'instrument',
        'location', 'generator', 'sample', 'distinct', 'children', 'children_truncated',
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
    expect(array_keys($item['sample']))->toBe(['actors', 'objects', 'targets', 'contexts', 'origins', 'results', 'instruments', 'locations', 'generators']);
    expect(array_keys($item['distinct']))->toBe(['actors', 'objects', 'targets', 'contexts', 'origins', 'results', 'instruments', 'locations', 'generators']);
    // A group's children are ordinary activity nodes.
    expect(array_keys($item['children'][0]))->toBe([
        'kind', 'id', 'verb', 'published_at', 'starts_at', 'ends_at', 'headline_template', 'headline',
        'glyph', 'glyph_intent', 'actor', 'object', 'target', 'context', 'origin', 'result', 'instrument', 'location', 'generator', 'data',
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
        foreach ($payload['items'] as &$item) {
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

    $viaFluent = $strip(Storyfeed::feed()->get()->toArray());

    // Same activity, authored by a message class, on a fresh manager.
    Activity::query()->forceDelete();
    app()->forgetInstance(StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();

    Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);

    Storyfeed::publish(new DeliveryWasConfirmed(Delivery::create(['tracking_number' => 'TN-2']), $user, $customer));

    $viaStory = $strip(Storyfeed::feed()->get()->toArray());

    // Object labels differ (different tracking numbers), so compare everything
    // the authoring layer could possibly have moved.
    expect($viaStory['payload_version'])->toBe($viaFluent['payload_version'])
        ->and(array_keys($viaStory['items'][0]))->toBe(array_keys($viaFluent['items'][0]))
        ->and($viaStory['items'][0]['headline_template'])->toBe($viaFluent['items'][0]['headline_template'])
        ->and($viaStory['items'][0]['glyph'])->toBe($viaFluent['items'][0]['glyph'])
        ->and($viaStory['items'][0]['glyph_intent'])->toBe($viaFluent['items'][0]['glyph_intent'])
        ->and($viaStory['items'][0]['verb'])->toBe($viaFluent['items'][0]['verb'])
        ->and($viaStory['items'][0]['actor'])->toBe($viaFluent['items'][0]['actor'])
        ->and($viaStory['items'][0]['target'])->toBe($viaFluent['items'][0]['target']);
});
