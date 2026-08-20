<?php

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;

it('resolves grammar in specificity order', function () {
    Storyfeed::grammar([
        'delivery.confirm' => ':actor confirmed :object for :target',
        'delivery.*' => ':actor did something with :object',
        '*.confirm' => ':actor confirmed :object',
        '*.*' => ':actor acted',
    ]);

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
    Storyfeed::icons([
        'delivery.confirm' => 'bi-truck',
        '*.*' => 'bi-lightning',
    ]);

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
    Storyfeed::objectTypes(['user' => 'Person', 'delivery' => 'Document']);

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
    Storyfeed::objectTypes(['widget' => 'ext:Widget']);

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

it('emits headline templates and icons in the payload', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object']);
    Storyfeed::icons(['delivery.confirm' => 'bi-truck']);

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['headline_template'])->toBe(':actor confirmed :object')
        ->and($item['headline'])->toBeNull()
        ->and($item['icon'])->toBe('bi-truck');
});

it('pre-renders closure grammar as headline with a null template', function () {
    Storyfeed::grammar([
        'delivery.confirm' => fn ($activity) => "Delivery {$activity->object_id} confirmed",
    ]);

    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::activity('confirm', $delivery)->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['headline_template'])->toBeNull()
        ->and($item['headline'])->toBe("Delivery {$delivery->id} confirmed");
});

it('resolves grammar for group nodes too', function () {
    Storyfeed::grammar(['delivery.upload' => ':actor uploaded deliveries']);

    foreach (range(1, 2) as $i) {
        Storyfeed::activity('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))->publish();
    }

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['kind'])->toBe('group')
        ->and($item['headline_template'])->toBe(':actor uploaded deliveries');
});

it('refuses a list of verbs, which would register the integer 0 as a verb', function () {
    // The silent version of this bug: `0 => 'order.placed'` registers a
    // vocabulary doctor believes in, and `verbs.strict` then rejects every real
    // verb against it. Found while writing tests for FeedAudience.
    expect(fn () => Storyfeed::verbs(['order.placed', 'order.delivered']))
        ->toThrow(InvalidArgumentException::class, "Storyfeed::verbs(['order.placed' => ActivityType::Update])");

    // The map form and the enum form are untouched.
    Storyfeed::verbs(['order.placed' => ActivityType::Create]);
    Storyfeed::verbs(ActivityVerb::class);

    expect(Storyfeed::declaredVerb('order.placed'))->toBeTrue()
        ->and(Storyfeed::declaredVerb('confirm'))->toBeTrue();
});

it('refuses a list of grammar templates, which would resolve for nothing', function () {
    // The silent shape of the same bug verbs() had: key 0 matches no
    // (type, verb) pair that will ever be asked for, so every headline stays
    // null and doctor reports the grammar as missing — pointing at the very
    // templates the developer is looking at.
    expect(fn () => Storyfeed::grammar([':actor confirmed :object']))
        ->toThrow(InvalidArgumentException::class, "Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object'])");

    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object']);

    expect(Storyfeed::templateKey('delivery', 'confirm'))->toBe('delivery.confirm');
});

it('still accepts a closure grammar entry under a string key', function () {
    Storyfeed::grammar(['delivery.confirm' => fn () => 'rendered']);

    expect(Storyfeed::templateKey('delivery', 'confirm'))->toBe('delivery.confirm');
});

it('refuses a list of aggregate templates', function () {
    expect(fn () => Storyfeed::aggregateGrammar([':actors uploaded :count files']))
        ->toThrow(InvalidArgumentException::class, 'actors.upload');

    Storyfeed::aggregateGrammar(['actors.upload' => ':actors uploaded :count files']);

    expect(Storyfeed::aggregateTemplateKey('actors', 'upload'))->toBe('actors.upload');
});

it('refuses a list of icons', function () {
    expect(fn () => Storyfeed::icons(['bi-truck']))
        ->toThrow(InvalidArgumentException::class, "Storyfeed::icons(['delivery.confirm' => 'bi-truck'])");

    Storyfeed::icons(['delivery.confirm' => 'bi-truck']);

    expect(Storyfeed::iconKey('delivery', 'confirm'))->toBe('delivery.confirm');
});

it('refuses a list of object types, the fifth registry with the same hole', function () {
    // Key 0 is not a morph alias, so every activity would serialize with no
    // AS2.0 object type and the JSON-LD would look merely under-specified.
    expect(fn () => Storyfeed::objectTypes(['delivery']))
        ->toThrow(InvalidArgumentException::class, "Storyfeed::objectTypes(['delivery' => 'Document'])");

    Storyfeed::objectTypes(['delivery' => ObjectType::Document]);

    expect(Storyfeed::objectType('delivery'))->toBe(ObjectType::Document);
});
