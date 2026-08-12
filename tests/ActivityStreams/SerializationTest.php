<?php

use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\Serialization\ActivitySerializer;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

function serialize_one(Activity $activity): array
{
    return app(ActivitySerializer::class)->activity(
        $activity->fresh(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext']),
    );
}

it('emits a spec-shaped Activity document', function () {
    Storyfeed::verbs(['confirm' => ActivityType::Update]);
    Storyfeed::objectTypes([
        'user' => ObjectType::Person,
        'delivery' => ObjectType::Document,
        'customer' => ObjectType::Organization,
    ]);

    $user = User::create(['name' => 'Sally Nguyen', 'email' => 'sally@example.com']);
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1042']);

    $activity = Storyfeed::activity('confirm', $delivery)->actor($user)->for($customer)->publish();

    $document = serialize_one($activity);

    expect($document['@context'])->toBe([
        'https://www.w3.org/ns/activitystreams',
        'https://ns.storyfeed.dev',
    ])
        ->and($document['id'])->toEndWith("/storyfeed/activities/{$activity->uid}")
        ->and($document['type'])->toBe('Update')
        ->and($document['sf:verb'])->toBe('confirm')
        ->and($document['actor']['type'])->toBe('Person')
        ->and($document['actor']['name'])->toBe('Sally Nguyen')
        ->and($document['actor']['id'])->toEndWith("/users/{$user->id}")
        ->and($document['object']['type'])->toBe('Document')
        ->and($document['object']['name'])->toBe('Delivery #TN-1042')
        ->and($document['object']['url'])->toEndWith("/deliveries/{$delivery->id}")
        ->and($document['target']['type'])->toBe('Organization')
        // xsd:dateTime, UTC designator, no microseconds.
        ->and($document['published'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('serializes unmapped verbs as the base Activity type, preserving sf:verb', function () {
    $activity = Storyfeed::activity()->verb('frobnicate', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $document = serialize_one($activity);

    expect($document['type'])->toBe('Activity')
        ->and($document['sf:verb'])->toBe('frobnicate');
});

it('degrades an intransitive mapping that carries an object — never drops the object', function () {
    Storyfeed::verbs(['arrive' => ActivityType::Arrive]);

    $activity = Storyfeed::activity('arrive', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $document = serialize_one($activity);

    // Emitting Arrive-with-object would be spec-invalid; dropping the
    // object would hide data. Base type + kept object is the honest out.
    expect($document['type'])->toBe('Activity')
        ->and($document['object'])->not->toBeNull()
        ->and($document['sf:verb'])->toBe('arrive');
});

it('keeps the intransitive type when no object is present', function () {
    Storyfeed::verbs(['arrive' => ActivityType::Arrive]);

    $activity = Storyfeed::activity()->verb('arrive')->publish();

    expect(serialize_one($activity)['type'])->toBe('Arrive');
});

it('serializes un-snapshotted entities as bare references', function () {
    // Legacy/backfill-pending row: raw create, no snapshots.
    $activity = Activity::query()->create([
        'verb' => 'confirm',
        'object_type' => 'delivery',
        'object_id' => 999,
        'published_at' => now(),
    ]);

    $document = serialize_one($activity);

    expect($document['object'])->toHaveKey('type')
        ->and($document['object'])->not->toHaveKey('name');
});

it('serializes a party with its own per-row type, Application winning over the Service default', function () {
    Storyfeed::as(Party::make('Platform', type: ObjectType::Application), function () {
        Storyfeed::record('sync', Delivery::create(['tracking_number' => 'TN-1']));
    });

    $document = serialize_one(Activity::query()->sole());

    expect($document['actor']['type'])->toBe('Application')
        ->and($document['actor']['name'])->toBe('Platform');
});

it('excludes presentation extras from the document', function () {
    Storyfeed::grammar(['*.*' => ':actor did :object'])->icons(['*.*' => 'bi-truck']);

    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $document = serialize_one($activity);

    $flat = json_encode($document);

    expect($flat)->not->toContain('headline')
        ->and($flat)->not->toContain('icon')
        ->and($flat)->not->toContain('component')
        ->and($flat)->not->toContain('modal');
});

it('emits only terms defined in the AS2 vocabulary or the sf: context', function () {
    Storyfeed::verbs(['confirm' => ActivityType::Update]);

    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))
        ->actor($user)
        ->for(Customer::create(['name' => 'Acme Co.']))
        ->publish();

    $document = serialize_one($activity);

    $context = json_decode(file_get_contents(__DIR__.'/../Fixtures/activitystreams.jsonld'), true);
    $specTerms = array_keys($context['@context']);
    $sfTerms = ['sf:verb', 'sf:group'];

    $assertTerms = function (array $node) use (&$assertTerms, $specTerms, $sfTerms) {
        foreach ($node as $term => $value) {
            if ($term !== '@context') {
                expect(in_array($term, $specTerms, true) || in_array($term, $sfTerms, true))
                    ->toBeTrue("Emitted term `{$term}` is not defined in the AS2 or sf: context.");
            }

            if (is_array($value) && array_is_list($value) === false) {
                $assertTerms($value);
            }
        }
    };

    $assertTerms($document);
});
