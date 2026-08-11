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
