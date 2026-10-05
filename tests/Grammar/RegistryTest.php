<?php

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;

it('resolves grammar in specificity order', function () {
    Story::for('delivery')->verb('confirm')->headline(':actor confirmed :object for :target');
    Story::for('delivery')->fallback()->headline(':actor did something with :object');
    Story::verb('confirm')->headline(':actor confirmed :object');
    Story::fallback()->headline(':actor acted');

    expect(Storyfeed::template('delivery', 'confirm'))->toBe(':actor confirmed :object for :target')
        ->and(Storyfeed::template('delivery', 'cancel'))->toBe(':actor did something with :object')
        ->and(Storyfeed::template('invoice', 'confirm'))->toBe(':actor confirmed :object')
        ->and(Storyfeed::template('invoice', 'void'))->toBe(':actor acted')
        ->and(Storyfeed::template(null, 'confirm'))->toBe(':actor confirmed :object');
});

it('returns null for unregistered grammar instead of guessing', function () {
    expect(Storyfeed::template('delivery', 'confirm'))->toBeNull();
});

it('resolves icons with the same wildcard order', function () {
    Story::for('delivery')->verb('confirm')->icon('bi-truck');
    Story::fallback()->icon('bi-lightning');

    expect(Storyfeed::icon('delivery', 'confirm'))->toBe('bi-truck')
        ->and(Storyfeed::icon('anything', 'else'))->toBe('bi-lightning');
});

it('maps verbs to AS2.0 activity types with overridable defaults', function () {
    expect(Storyfeed::activityType('create'))->toBe(ActivityType::Create)
        ->and(Storyfeed::activityType('share'))->toBe(ActivityType::Announce)
        ->and(Storyfeed::activityType('confirm'))->toBeNull();

    Storyfeed::verbs(['confirm' => 'Update']);

    expect(Storyfeed::activityType('confirm'))->toBe(ActivityType::Update);
});

it('maps morph aliases to AS2.0 object types', function () {
    Story::for('user')->fallback()->activityStreamsType('Person');
    Story::for('delivery')->fallback()->activityStreamsType('Document');

    expect(Storyfeed::objectType('user'))->toBe(ObjectType::Person)
        ->and(Storyfeed::objectType('unknown'))->toBeNull();
});

it('always yields a wire value, falling back for unmapped terms', function () {
    expect(Storyfeed::activityTypeValue('create'))->toBe('Create')
        ->and(Storyfeed::activityTypeValue('nonesuch'))->toBe('Activity')
        ->and(Storyfeed::objectTypeValue('nonesuch'))->toBe('Object');
});

it('preserves unrecognized extension types verbatim', function () {
    Storyfeed::verbs(['frobnicate' => 'sf:Frobnicate']);
    Story::for('widget')->fallback()->activityStreamsType('ext:Widget');

    // tryFrom-then-discard is the data-loss bug that breaks federation.
    expect(Storyfeed::activityType('frobnicate'))->toBe('sf:Frobnicate')
        ->and(Storyfeed::activityTypeValue('frobnicate'))->toBe('sf:Frobnicate')
        ->and(Storyfeed::objectType('widget'))->toBe('ext:Widget');
});

it('accepts loose spellings when registering types', function () {
    Storyfeed::verbs([
        'a' => 'create',
        'b' => 'as:Update',
        'c' => 'https://www.w3.org/ns/activitystreams#Announce',
    ]);

    expect(Storyfeed::activityType('a'))->toBe(ActivityType::Create)
        ->and(Storyfeed::activityType('b'))->toBe(ActivityType::Update)
        ->and(Storyfeed::activityType('c'))->toBe(ActivityType::Announce);
});

it('registers a whole vocabulary from a FeedVerb enum', function () {
    Storyfeed::verbs(ActivityVerb::class);

    expect(Storyfeed::activityType('confirm'))->toBe(ActivityType::Update)
        ->and(Storyfeed::activityType('upload'))->toBe(ActivityType::Add)
        ->and(Storyfeed::activityType('comment'))->toBe(ActivityType::Create);
});

it('emits headline templates and glyph tokens in the payload', function () {
    Story::for('delivery')->verb('confirm')->headline(':actor confirmed :object')->icon('bi-truck');

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['headline_template'])->toBe(':actor confirmed :object')
        ->and($item['headline'])->toBeNull()
        ->and($item['glyph'])->toBe('bi-truck');
});

it('pre-renders closure grammar as headline with a null template', function () {
    Story::for('delivery')->verb('confirm')->headline(fn ($activity) => "Delivery {$activity->object()?->key()} confirmed");

    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::activity('confirm', $delivery)->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['headline_template'])->toBeNull()
        ->and($item['headline'])->toBe("Delivery {$delivery->id} confirmed");
});

it('resolves grammar for group nodes too', function () {
    Story::for('delivery')->verb('upload')->headline(':actor uploaded deliveries');

    foreach (range(1, 2) as $i) {
        Storyfeed::activity('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))->publish();
    }

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['kind'])->toBe('group')
        ->and($item['headline_template'])->toBe(':actor uploaded deliveries');
});

it('refuses a list of verbs, which would register the integer 0 as a verb', function () {
    // The silent version of this bug: `0 => 'placed'` registers a
    // vocabulary doctor believes in, and `verbs.strict` then rejects every real
    // verb against it. Found while writing tests for FeedAudience.
    expect(fn () => Storyfeed::verbs(['placed', 'delivered']))
        ->toThrow(InvalidArgumentException::class, "Storyfeed::verbs(['placed' => ActivityType::Update])");

    // The map form and the enum form are untouched.
    Storyfeed::verbs(['placed' => ActivityType::Create]);
    Storyfeed::verbs(ActivityVerb::class);

    expect(Storyfeed::declaredVerb('placed'))->toBeTrue()
        ->and(Storyfeed::declaredVerb('confirm'))->toBeTrue();
});

it('still accepts a closure grammar entry under a string key', function () {
    Story::for('delivery')->verb('confirm')->headline(fn () => 'rendered');

    expect(Storyfeed::templateKey('delivery', 'confirm'))->toBe('delivery.confirm');
});

it('refuses an enum that forgot to implement FeedVerb, instead of registering nothing', function () {
    // The silent version registered NO verbs at all, and doctor then reported
    // `verbs.undeclared` — which reads as "you have not declared a vocabulary"
    // to someone who just did.
    expect(fn () => Storyfeed::verbs(PlainVerbEnum::class))
        ->toThrow(InvalidArgumentException::class, 'not a backed enum implementing');

    expect(fn () => Storyfeed::verbs('App\\Enums\\Renamed'))
        ->toThrow(InvalidArgumentException::class, 'is not a class');

    Storyfeed::verbs(ActivityVerb::class);

    expect(Storyfeed::declaredVerb('confirm'))->toBeTrue();
});

enum PlainVerbEnum: string
{
    case Confirm = 'confirm';
}
