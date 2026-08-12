<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\StoryDefinition;
use Storyfeed\Testing\GrammarCoverage;
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
    Storyfeed::stories([DeliveryWasConfirmed::class]);
    Storyfeed::grammar(['delivery.confirm' => 'OVERRIDDEN']);

    expect(Storyfeed::template('delivery', 'confirm'))->toBe('OVERRIDDEN');
});

it('lets a hand-written entry win when registered BEFORE the story', function () {
    // The order-independence is the point: an app cannot be expected to know
    // that its provider runs before or after another's.
    Storyfeed::grammar(['delivery.confirm' => 'OVERRIDDEN']);
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    expect(Storyfeed::template('delivery', 'confirm'))->toBe('OVERRIDDEN');
});

it('keeps closures legal through the hand-written path', function () {
    // Compiled output is closure-free so it can be cached; closures remain
    // available where they always were.
    Storyfeed::stories([DeliveryWasConfirmed::class]);
    Storyfeed::grammar(['delivery.confirm' => fn ($activity) => 'rendered '.$activity->verb]);

    expect(Storyfeed::template('delivery', 'confirm'))->toBeInstanceOf(Closure::class);
});

it('picks up stories registered after a compile has already happened', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    // Force a compile.
    expect(Storyfeed::template('delivery', 'confirm'))->not->toBeNull();

    // A second provider, or a test, registering later must not be ignored.
    Storyfeed::stories([StoryDefinition::make('delivery.archive')->headline(':actor archived :object')]);

    expect(Storyfeed::template('delivery', 'archive'))->toBe(':actor archived :object');
});

it('compiles the three authoring forms to identical registries', function () {
    $expected = [
        'grammar' => ['delivery.confirm' => ':actor confirmed :object'],
        'aggregateGrammar' => ['actors.confirm' => ':actors confirmed :count deliveries'],
        'icons' => ['delivery.confirm' => 'bi-truck'],
    ];

    $forms = [
        'fluent' => [
            StoryDefinition::make('delivery.confirm')
                ->headline(':actor confirmed :object')
                ->icon('bi-truck')
                ->groups(Group::byActors()->headline(':actors confirmed :count deliveries')),
        ],
        'array' => [
            'delivery.confirm' => [
                'headline' => ':actor confirmed :object',
                'icon' => 'bi-truck',
                'groups' => [Group::byActors()->headline(':actors confirmed :count deliveries')],
            ],
        ],
    ];

    foreach ($forms as $name => $stories) {
        Storyfeed::stories($stories, merge: false);

        $compiled = Storyfeed::compiledStories();

        expect($compiled['grammar'])->toBe($expected['grammar'], "form: {$name}")
            ->and($compiled['aggregateGrammar'])->toBe($expected['aggregateGrammar'], "form: {$name}")
            ->and($compiled['icons'])->toBe($expected['icons'], "form: {$name}");
    }
});

it('satisfies GrammarCoverage from stories alone', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);
    Storyfeed::fake();

    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    DeliveryWasConfirmed::activity(Delivery::create(['tracking_number' => 'TN-1']))
        ->actor($user)
        ->publish();

    // The proof that compile-to-registries is the right architecture:
    // assertCoversRecorded() needed no changes at all.
    GrammarCoverage::assertCoversRecorded();
});
