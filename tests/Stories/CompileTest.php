<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Axis;
use Storyfeed\Grouping\Group;
use Storyfeed\StoryDefinition;
use Storyfeed\StoryfeedManager;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveriesWereUploaded;
use Workbench\App\Stories\DeliveryWasConfirmed;

/*
 * The load-bearing test of the whole layer: a Story and the equivalent
 * hand-written provider block must compile to IDENTICAL registries. If that
 * holds, the payload contract is immune to authoring-layer churn — which is
 * the architectural promise that made it safe to keep iterating on this after
 * the contract froze.
 */

it('compiles to exactly what the hand-written registries would hold', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    $fromStory = [
        'grammar' => Storyfeed::registeredGrammar(),
        'aggregateGrammar' => Storyfeed::registeredAggregateGrammar(),
        'icons' => Storyfeed::registeredIcons(),
    ];

    // A second manager, configured the old way.
    app()->forgetInstance(StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();

    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object for :target'])
        ->icons(['delivery.confirm' => 'bi-truck'])
        ->aggregateGrammar([
            'actors.confirm' => ':actors confirmed :count deliveries for :target',
            'targets.confirm' => ':actor confirmed deliveries for :targets',
            'repeat.confirm' => ':actor confirmed :count deliveries',
        ]);

    expect(Storyfeed::registeredGrammar())->toBe($fromStory['grammar'])
        ->and(Storyfeed::registeredAggregateGrammar())->toBe($fromStory['aggregateGrammar'])
        ->and(Storyfeed::registeredIcons())->toBe($fromStory['icons']);
});

it('registers the verb even when the story declares no AS2 type', function () {
    Storyfeed::stories([
        StoryDefinition::make('delivery.frobnicate')->headline(':actor frobnicated :object'),
    ]);

    // Without this, strict mode would throw UnknownVerb for every
    // story-authored verb whose vocabulary is not also in an enum — a
    // guaranteed day-one bug report.
    expect(Storyfeed::registeredVerbs())->toHaveKey('frobnicate')
        ->and(Storyfeed::declaredVerb('frobnicate'))->toBeTrue();
});

it('takes the AS2 mapping from a FeedVerb enum case', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    expect(Storyfeed::activityTypeValue('confirm'))->toBe('Update');
});

it('compiles a composite into both the aggregate key and the parent singular', function () {
    Storyfeed::stories([DeliveriesWereUploaded::class]);

    expect(Storyfeed::registeredAggregateGrammar())->toHaveKey('composite.upload')
        // The second, unlisted registry: a composite parent is object-less, so
        // `delivery.upload` never resolves for it.
        ->and(Storyfeed::registeredGrammar())->toHaveKey('*.upload')
        ->and(Storyfeed::template(null, 'upload'))->toBe(':actor uploaded deliveries');
});

it('publishes through a story identically to the builder', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $other = Delivery::create(['tracking_number' => 'TN-2']);

    $viaStory = DeliveryWasConfirmed::publish($delivery);
    $viaBuilder = Storyfeed::activity(ActivityVerb::Confirm, $other)->publish();

    expect($viaStory->verb)->toBe($viaBuilder->verb)
        ->and($viaStory->object_type)->toBe($viaBuilder->object_type);

    // Same grouping hashes for equivalent activities — the story is a
    // different authoring surface, not a different write path.
    $hashes = fn ($activity) => $activity->groupings()->pluck('bucket')->sort()->values()->all();

    expect($hashes($viaStory))->toBe($hashes($viaBuilder));
});

it('exposes the chainable builder rather than a parallel surface', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    $activity = DeliveryWasConfirmed::activity(Delivery::create(['tracking_number' => 'TN-1']))
        ->actor(User::create(['name' => 'Sally', 'email' => 's@example.com']))
        ->for(Customer::create(['name' => 'Acme']))
        ->publish();

    expect($activity->target_type)->toBe('customer')
        ->and($activity->actor_type)->toBe('user');
});

it('emits closure-free output, so a manifest can var_export it', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class, DeliveriesWereUploaded::class]);

    $compiled = Storyfeed::compiledStories();

    foreach ($compiled as $registry) {
        foreach ($registry as $value) {
            expect($value)->not->toBeInstanceOf(Closure::class);
        }
    }

    expect(var_export($compiled, true))->toBeString();
});

it('groups by verb, not by axis — the ergonomic point', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    // All three of `confirm`'s aggregate headlines came from ONE file. In the
    // raw registry they are three entries in an axis-ordered array, which is
    // how one verb's headlines end up 40+ lines apart.
    $keys = array_keys(Storyfeed::registeredAggregateGrammar());

    expect($keys)->toContain('actors.confirm')
        ->toContain('targets.confirm')
        ->toContain('repeat.confirm');
});

it('accepts an ad-hoc group on a custom axis', function () {
    Storyfeed::axes([
        Axis::make('scene')->key('v:ca!:cid!:d'),
    ]);

    Storyfeed::stories([
        StoryDefinition::make('delivery.confirm')
            ->headline(':actor confirmed :object')
            ->groups(Group::on('scene')->headline(':actors confirmed :count deliveries in :context')),
    ]);

    expect(Storyfeed::aggregateTemplate('scene', 'confirm'))
        ->toBe(':actors confirmed :count deliveries in :context');
});
