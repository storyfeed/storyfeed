<?php

use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\Stories\Verb;
use Storyfeed\StoryfeedManager;
use Storyfeed\Testing\HeadlineCoverage;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveryWasConfirmed;

/*
 * Explicit supplied-field overrides retain package behavior regardless of
 * provider registration order. Ordinary competing owners remain errors.
 */

it('lets an explicit override win when registered after the story', function () {
    Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);
    Story::for(Delivery::class)->verb('confirm')->override()->headline('OVERRIDDEN');

    expect(Storyfeed::template('delivery', 'confirm'))->toBe('OVERRIDDEN');
});

it('lets an explicit override win when registered BEFORE the story', function () {
    // The order-independence is the point: an app cannot be expected to know
    // that its provider runs before or after another's.
    Story::for(Delivery::class)->verb('confirm')->override()->headline('OVERRIDDEN');
    Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);

    expect(Storyfeed::template('delivery', 'confirm'))->toBe('OVERRIDDEN');
});

it('keeps closures legal through the explicit override', function () {
    // Fluent overrides preserve deferred closure headline resolution.
    Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);
    Story::for(Delivery::class)->verb('confirm')->override()->headline(fn ($activity) => 'rendered '.$activity->verb());

    expect(Storyfeed::template('delivery', 'confirm'))->toBeInstanceOf(Closure::class);
});

it('picks up stories registered after a compile has already happened', function () {
    Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);

    // Force a compile.
    expect(Storyfeed::template('delivery', 'confirm'))->not->toBeNull();

    // A second provider, or a test, registering later must not be ignored.
    defineStories(Verb::make('delivery.archive')->headline(':actor archived :object'));

    expect(Storyfeed::template('delivery', 'archive'))->toBe(':actor archived :object');
});

it('compiles a message class and the equivalent line to identical registries', function () {
    $forms = [
        'class' => fn () => Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class),
        'line' => fn () => Story::for(Delivery::class)->verb(ActivityVerb::Confirm)
            ->headline(':actor confirmed :object for :target')
            ->icon('bi-truck')
            ->grouped(Group::repeat()->headline(':actor confirmed :count deliveries')),
    ];

    $compiled = [];

    foreach ($forms as $name => $register) {
        app()->forgetInstance(StoryfeedManager::class);
        Storyfeed::clearResolvedInstances();

        $register();

        $compiled[$name] = Storyfeed::compiledStories();
    }

    foreach (['grammar', 'aggregateGrammar', 'icons', 'verbs'] as $registry) {
        expect($compiled['line'][$registry])->toBe($compiled['class'][$registry], "registry: {$registry}");
    }
});

it('satisfies HeadlineCoverage from stories alone', function () {
    Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);
    Storyfeed::fake();

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    Storyfeed::publish(new DeliveryWasConfirmed(Delivery::create(['tracking_number' => 'TN-1']), $user));

    // The proof that compile-to-registries is the right architecture:
    // assertCoversRecorded() needed no changes at all.
    HeadlineCoverage::assertCoversRecorded();
});
