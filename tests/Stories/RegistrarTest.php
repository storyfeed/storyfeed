<?php

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedHeadline;
use Storyfeed\FeedNoun;
use Storyfeed\Grouping\Group;
use Storyfeed\Grouping\GroupBuilder;
use Storyfeed\Stories\Registrar;
use Storyfeed\Stories\TypeScope;
use Storyfeed\Stories\Verb;
use Storyfeed\StoryfeedManager;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

/*
 * The Story facade is a front door onto Stories\Verb: every call registers
 * a definition that compiles through CompileStories beside Story classes. The
 * load-bearing test is the first one — the fluent form and the hand-written
 * arrays must produce the same registries.
 */

/** @return array<string, mixed> */
function registries(): array
{
    return [
        'grammar' => Storyfeed::registeredGrammar(),
        'aggregateGrammar' => Storyfeed::registeredAggregateGrammar(),
        'actorlessGrammar' => Storyfeed::registeredActorlessGrammar(),
        'icons' => Storyfeed::registeredIcons(),
        'glyphIntents' => Storyfeed::registeredGlyphIntents(),
        'nouns' => Storyfeed::registeredNouns(),
        'objectType' => Storyfeed::objectType('delivery'),
        'verbs' => array_intersect_key(Storyfeed::registeredVerbs(), array_flip(['confirm', 'upload', 'ship'])),
    ];
}

function freshManager(): void
{
    app()->forgetInstance(StoryfeedManager::class);
    app()->forgetInstance(Registrar::class);
    Storyfeed::clearResolvedInstances();
    Story::clearResolvedInstances();
}

it('compiles the fluent form to exactly the registries the arrays hold', function () {
    Story::for(Delivery::class)->group(function () {
        Story::verb(ActivityVerb::Confirm)
            ->headline(':actor confirmed :object[ for :target]')
            ->anonymousHeadline(':object was confirmed')
            ->icon('bi-truck')
            ->intent('success');

        Story::verb('upload')->noun('file|files');
        Story::verb('ship')->headline(':actor shipped :object');
        Story::fallback()->icon('bi-box');
    });

    Story::for(Delivery::class)->noun('delivery|deliveries')->activityStreamsType(ObjectType::Document);

    Story::verb('confirm')->grouped(
        Group::repeat()->headline(':actor confirmed :count deliveries'),
        fn (GroupBuilder $group) => $group->actors(':actors confirmed deliveries'),
    );

    Story::fallback()->icon('bi-activity');

    $fluent = registries();

    freshManager();

    Storyfeed::grammar([
        'delivery.confirm' => ':actor confirmed :object[ for :target]',
        'delivery.ship' => ':actor shipped :object',
    ])
        ->actorlessGrammar(['delivery.confirm' => ':object was confirmed'])
        ->icons(['delivery.confirm' => 'bi-truck', 'delivery.*' => 'bi-box', '*.*' => 'bi-activity'])
        ->glyphIntents(['delivery.confirm' => 'success'])
        ->nouns(['delivery.upload' => 'file|files', 'delivery' => 'delivery|deliveries'])
        ->objectTypes(['delivery' => ObjectType::Document])
        ->aggregateGrammar([
            'repeat.confirm' => ':actor confirmed :count deliveries',
            'actors.confirm' => ':actors confirmed deliveries',
        ])
        ->verbs(['confirm' => ActivityType::Update, 'upload' => 'Activity', 'ship' => 'Activity']);

    $arrays = registries();

    // Registration order differs between the two forms; the entries don't.
    foreach (['grammar', 'aggregateGrammar', 'actorlessGrammar', 'icons', 'glyphIntents', 'nouns', 'verbs'] as $registry) {
        ksort($fluent[$registry]);
        ksort($arrays[$registry]);
    }

    expect($fluent)->toEqual($arrays);
});

it('keeps the verb AS2 type a FeedVerb case carries', function () {
    Story::verb(ActivityVerb::Confirm)->headline(':actor confirmed :object');

    expect(Storyfeed::activityTypeValue('confirm'))->toBe('Update');
});

it('resolves for() through the morph map, and takes aliases and lists', function () {
    Story::for(Delivery::class)->verb('confirm')->headline('A');
    Story::for('customer')->verb('confirm')->headline('B');
    Story::for([Delivery::class, 'courier'])->verb('ship')->headline('C');

    expect(Storyfeed::registeredGrammar())->toMatchArray([
        'delivery.confirm' => 'A',
        'customer.confirm' => 'B',
        'delivery.ship' => 'C',
        'courier.ship' => 'C',
    ]);
});

it('scopes only the calls inside group(), and hands the closure the scope', function () {
    $received = null;

    Story::for(Delivery::class)->group(function (TypeScope $delivery) use (&$received) {
        $received = $delivery;
        Story::verb('confirm')->headline('inside');
        $delivery->verb('ship')->headline('through the scope');
    });

    Story::verb('confirm')->headline('outside');

    expect($received)->toBeInstanceOf(TypeScope::class)
        ->and(Storyfeed::registeredGrammar())->toMatchArray([
            'delivery.confirm' => 'inside',
            'delivery.ship' => 'through the scope',
            '*.confirm' => 'outside',
        ]);
});

it('pops the scope when the group closure throws', function () {
    try {
        Story::for(Delivery::class)->group(fn () => throw new RuntimeException('boom'));
    } catch (RuntimeException) {
    }

    Story::verb('confirm')->headline('unscoped');

    expect(Storyfeed::registeredGrammar())->toHaveKey('*.confirm');
});

it('refuses a nested scope', function () {
    expect(fn () => Story::for(Delivery::class)->group(fn () => Story::for(Customer::class)))
        ->toThrow(StoryMisconfigured::class, 'scopes do not nest');
});

it('chains verbs with a closure and returns the builder without one', function () {
    $scope = Story::for(Delivery::class)
        ->verb('confirm', fn (Verb $verb) => $verb->headline(':actor confirmed :object'))
        ->verb('ship', fn (Verb $verb) => $verb->headline(':actor shipped :object'))
        ->fallback(fn (Verb $fallback) => $fallback->icon('bi-box'));

    expect($scope)->toBeInstanceOf(TypeScope::class)
        ->and(Story::for(Delivery::class)->verb('upload'))->toBeInstanceOf(Verb::class)
        ->and(Storyfeed::registeredGrammar())->toMatchArray([
            'delivery.confirm' => ':actor confirmed :object',
            'delivery.ship' => ':actor shipped :object',
        ])
        ->and(Storyfeed::registeredIcons())->toHaveKey('delivery.*');
});

it('compiles fallback() to type.* and *.*', function () {
    Story::for(Delivery::class)->fallback()->headline(':actor did something to :object');
    Story::fallback()->headline(':actor did something');

    expect(Storyfeed::registeredGrammar())->toMatchArray([
        'delivery.*' => ':actor did something to :object',
        '*.*' => ':actor did something',
    ]);
});

it('does not declare * as a verb for a wildcard key', function () {
    // Defect 2 of todo 1311: `order.*` put `*` in the verb registry, where it
    // reached storyfeed:verbs and satisfied verbs.strict.
    Storyfeed::stories([Verb::make('delivery.*')->headline(':actor did :object')]);
    Story::fallback()->icon('bi-activity');
    Story::for(Delivery::class)->noun('delivery|deliveries');

    expect(Storyfeed::compiledStories()['verbs'])->not->toHaveKey('*')
        ->and(Storyfeed::declaredVerb('*'))->toBeFalse()
        ->and(Storyfeed::registeredGrammar())->toHaveKey('delivery.*');
});

it('names both sources when one key is defined twice', function () {
    // Defect 3 of todo 1311: two ad-hoc definitions shared the source string
    // `ad-hoc [key]`, so the conflict guard saw one author and the second won.
    Storyfeed::stories([Verb::make('delivery.confirm')->headline('FIRST')]);
    Storyfeed::stories([Verb::make('delivery.confirm')->headline('SECOND')]);

    $first = __LINE__ - 3;
    $second = __LINE__ - 3;

    expect(fn () => Storyfeed::compiledStories())->toThrow(
        StoryMisconfigured::class,
        '[delivery.confirm] is defined twice: '.relativeTo(__FILE__).":{$first} and ".relativeTo(__FILE__).":{$second}",
    );
});

it('names both lines when the registrar defines one key twice', function () {
    Story::for(Delivery::class)->verb('confirm')->icon('a');
    Story::for(Delivery::class)->verb('confirm')->icon('b');

    $first = __LINE__ - 3;
    $second = __LINE__ - 3;

    expect(fn () => Storyfeed::compiledStories())->toThrow(
        StoryMisconfigured::class,
        '[delivery.confirm] is defined twice: '.relativeTo(__FILE__).":{$first} and ".relativeTo(__FILE__).":{$second}",
    );
});

it('lets two definitions of one key set different registries', function () {
    Story::verb('confirm')->headline(':actor confirmed :object');
    Story::verb('confirm')->grouped(Group::repeat()->headline(':actor confirmed :count things'));

    expect(Storyfeed::registeredGrammar())->toHaveKey('*.confirm')
        ->and(Storyfeed::registeredAggregateGrammar())->toHaveKey('repeat.confirm');
});

it('builds groups from a closure identically to Group objects', function () {
    Story::verb('confirm')->grouped(fn (GroupBuilder $group) => $group
        ->repeat(':actor confirmed :count deliveries')
        ->actors(':actors confirmed deliveries')
        ->axis('targets', ':actor confirmed deliveries for :targets'));

    $fromClosure = Storyfeed::registeredAggregateGrammar();

    freshManager();

    Story::verb('confirm')->grouped(
        Group::repeat()->headline(':actor confirmed :count deliveries'),
        Group::byActors()->headline(':actors confirmed deliveries'),
        Group::byTargets()->headline(':actor confirmed deliveries for :targets'),
    );

    expect($fromClosure)->toBe(Storyfeed::registeredAggregateGrammar());
});

it('keeps the compile-time guards for the registrar', function () {
    Story::verb('confirm')->grouped(fn (GroupBuilder $group) => $group->axis('nope', ':actor did it'));

    expect(fn () => Storyfeed::compiledStories())->toThrow(StoryMisconfigured::class);
});

it('refuses a noun on an unscoped verb and an AS2 object type without a type', function () {
    Story::verb('upload')->noun('file|files');

    expect(fn () => Storyfeed::compiledStories())->toThrow(StoryMisconfigured::class, 'sets a noun on the unscoped verb');

    freshManager();

    Story::fallback()->activityStreamsType(ObjectType::Document);

    expect(fn () => Storyfeed::compiledStories())->toThrow(StoryMisconfigured::class, 'without an object type');
});

it('sets the global noun with Story::fallback()->noun()', function () {
    Story::fallback()->noun('thing|things');

    expect(Storyfeed::registeredNouns())->toBe(['*' => 'thing|things']);
});

it('validates nouns and anonymous headlines when they are written', function () {
    expect(fn () => Story::for(Delivery::class)->noun('delivery'))
        ->toThrow(InvalidArgumentException::class, 'has only one form')
        ->and(fn () => Story::verb('confirm')->anonymousHeadline(':actor confirmed :object'))
        ->toThrow(InvalidArgumentException::class, 'must not contain :actor');
});

it('supports when() on a definition', function () {
    Story::verb('confirm')->headline(':actor confirmed :object')
        ->when(true, fn (Verb $verb) => $verb->icon('bi-bug'))
        ->when(false, fn (Verb $verb) => $verb->intent('danger'));

    expect(Storyfeed::registeredIcons())->toHaveKey('*.confirm')
        ->and(Storyfeed::registeredGlyphIntents())->not->toHaveKey('*.confirm');
});

it('loses to a hand-written registration, like every compiled entry', function () {
    Story::for(Delivery::class)->verb('confirm')->headline('compiled')->anonymousHeadline('compiled');
    Story::for(Delivery::class)->noun('compiled|compiled');

    Storyfeed::grammar(['delivery.confirm' => 'hand-written'])
        ->actorlessGrammar(['delivery.confirm' => 'hand-written'])
        ->nouns(['delivery' => 'hand|hands']);

    expect(Storyfeed::template('delivery', 'confirm'))->toBe('hand-written')
        ->and(Storyfeed::actorlessTemplate('delivery', 'confirm'))->toBe('hand-written')
        ->and(Storyfeed::noun('delivery', 'confirm'))->toBe('hand|hands');
});

it('accepts the new keys in the array form', function () {
    Storyfeed::stories([
        'delivery.confirm' => [
            'headline' => FeedHeadline::trans('feed.confirmed'),
            'anonymousHeadline' => ':object was confirmed',
            'noun' => FeedNoun::trans('nouns.delivery'),
            'activityStreamsType' => 'Document',
        ],
    ]);

    expect(Storyfeed::registeredActorlessGrammar())->toBe(['delivery.confirm' => ':object was confirmed'])
        ->and(Storyfeed::registeredNouns()['delivery.confirm'])->toBeInstanceOf(FeedNoun::class)
        ->and(Storyfeed::objectTypeValue('delivery'))->toBe('Document');
});

function relativeTo(string $file): string
{
    $base = app()->basePath();

    return str_starts_with($file, $base.DIRECTORY_SEPARATOR) ? substr($file, strlen($base) + 1) : $file;
}
