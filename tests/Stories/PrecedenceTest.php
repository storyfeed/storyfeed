<?php

use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\Stories\Verb;
use Storyfeed\StoryfeedManager;
use Storyfeed\Testing\GrammarCoverage;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveryWasConfirmed;

/*
 * The registries remain the documented substrate and the PERMANENT escape
 * hatch. An escape hatch you cannot use to override is not an escape hatch —
 * so a hand-written entry wins, and it wins regardless of registration order,
 * because compilation is deferred to App::booted().
 */

it('lets a hand-written entry win when registered after the story', function () {
    Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);
    Storyfeed::grammar(['delivery.confirm' => 'OVERRIDDEN']);

    expect(Storyfeed::template('delivery', 'confirm'))->toBe('OVERRIDDEN');
});

it('lets a hand-written entry win when registered BEFORE the story', function () {
    // The order-independence is the point: an app cannot be expected to know
    // that its provider runs before or after another's.
    Storyfeed::grammar(['delivery.confirm' => 'OVERRIDDEN']);
    Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);

    expect(Storyfeed::template('delivery', 'confirm'))->toBe('OVERRIDDEN');
});

it('keeps closures legal through the hand-written path', function () {
    // Compiled output is closure-free so it can be cached; closures remain
    // available where they always were.
    Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);
    Storyfeed::grammar(['delivery.confirm' => fn ($activity) => 'rendered '.$activity->verb]);

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

it('satisfies GrammarCoverage from stories alone', function () {
    Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);
    Storyfeed::fake();

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    Storyfeed::publish(new DeliveryWasConfirmed(Delivery::create(['tracking_number' => 'TN-1']), $user));

    // The proof that compile-to-registries is the right architecture:
    // assertCoversRecorded() needed no changes at all.
    GrammarCoverage::assertCoversRecorded();
});
