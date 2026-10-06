<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Party;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('resolves or creates a party by slugged key', function () {
    $a = Party::make('Concur Web Service');
    $b = Party::make('Concur Web Service');

    expect($a->id)->toBe($b->id)
        ->and($a->key)->toBe('concur-web-service')
        ->and($a->type)->toBe('Service')
        ->and(Party::query()->count())->toBe(1);
});

it('renames without forking identity when the key is kept', function () {
    $original = Party::make('System');

    $renamed = Party::make('Platform', key: 'system');

    expect($renamed->id)->toBe($original->id)
        ->and($renamed->name)->toBe('Platform')
        ->and(Party::query()->count())->toBe(1);
});

it('accepts an explicit activity streams type', function () {
    $party = Party::make('Storyfeed', type: ObjectType::Application);

    expect($party->type)->toBe('Application');
});

it('records a party as the actor', function () {
    $activity = Storyfeed::activity('sync', Delivery::create(['tracking_number' => 'TN-1']))
        ->actor(Party::make('Concur Web Service'))
        ->publish();

    expect($activity->actor_type)->toBe('storyfeed.party')
        ->and($activity->cached_actor_id)->not->toBeNull();
});

it('accepts a bare string in every role', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    $activity = Storyfeed::record(
        ActivityVerb::Upload,
        object: 'Q3 Import',
        actor: $sally,
        target: 'Concur',
        context: 'Migration',
    );

    expect($activity->object_type)->toBe('storyfeed.party')
        ->and($activity->target_type)->toBe('storyfeed.party')
        ->and($activity->context_type)->toBe('storyfeed.party')
        ->and(Party::query()->pluck('key')->sort()->values()->all())
        ->toBe(['concur', 'migration', 'q3-import']);
});

it('produces the same row from a string as from an explicit party', function () {
    $viaString = Storyfeed::activity('sync')->actor('Concur')->publish();
    $viaModel = Storyfeed::activity('sync')->actor(Party::make('Concur'))->publish();

    expect($viaString->actor_id)->toEqual($viaModel->actor_id)
        ->and($viaString->actor_type)->toBe($viaModel->actor_type)
        ->and(Party::query()->count())->toBe(1);
});

it('ignores an empty string rather than creating a blank party', function () {
    $activity = Storyfeed::activity('ping')->actor('   ')->publish();

    expect($activity->actor_type)->toBeNull()
        ->and(Party::query()->count())->toBe(0);
});

it('emits a party as a normal payload entity with no url', function () {
    Storyfeed::activity('sync', Delivery::create(['tracking_number' => 'TN-1']))
        ->actor('Concur Web Service')
        ->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['actor'])->toMatchArray([
        'type' => 'storyfeed.party',
        'label' => 'Concur Web Service',
        'url' => null,
    ])
        ->and($item['actor']['data']['key'])->toBe('concur-web-service');
});

it('propagates a rename to existing activities via the snapshot', function () {
    $party = Party::make('System');

    Storyfeed::activity('ping')->actor($party)->publish();

    Party::make('Platform', key: 'system');

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['actor']['label'])->toBe('Platform');
});

it('keeps activities when a party is deleted', function () {
    $party = Party::make('Retired Integration');

    Storyfeed::activity('ping')->actor($party)->publish();

    $party->delete();

    // History outlives the integration — no cascade, unlike InteractsWithFeed.
    expect(Activity::query()->count())->toBe(1);
});

it('scopes the feed by party name, and matches nothing for an unknown name', function () {
    Storyfeed::activity('sync')->actor('Concur')->publish();
    Storyfeed::activity('sync')->actor('Workday')->publish();

    expect(Storyfeed::feed()->actor('Concur')->get()->toArray()['items'])->toHaveCount(1)
        ->and(Storyfeed::feed()->actor('Nonesuch')->get()->toArray()['items'])->toHaveCount(0);
});

it('never creates a party from the read path', function () {
    Storyfeed::feed()->actor('Ghost')->get();

    expect(Party::query()->count())->toBe(0);
});

it('collapses a shared target party on the actors axis', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 's@example.com']);
    $bob = User::create(['name' => 'Bob', 'email' => 'b@example.com']);

    Storyfeed::activity('push', Delivery::create(['tracking_number' => 'A']))->actor($sally)->to('Concur')->publish();
    Storyfeed::activity('push', Delivery::create(['tracking_number' => 'B']))->actor($bob)->to('Concur')->publish();

    $hashes = Grouping::query()->where('bucket', 'actors')->pluck('hash')->unique();

    expect($hashes)->toHaveCount(1);
});

it('links a string party using the declared external home from the docs example', function () {
    $party = Party::make('TalkingFeed', url: 'https://talkingfeed.app');
    Storyfeed::activity('launch')->object('TalkingFeed')->publish();

    expect($party->fresh()->data['$url'])->toBe('https://talkingfeed.app')
        ->and(Storyfeed::feed()->get()->toArray()['items'][0]['object']['url'])->toBe('https://talkingfeed.app');
});

it('preserves the external home through renames and replacement app data', function () {
    $party = Party::make('System', data: ['version' => 1], url: 'https://example.com');
    Storyfeed::activity('ping')->actor($party)->publish();

    $renamed = Party::make('Platform', key: 'system', data: ['version' => 2]);
    $actor = Storyfeed::feed()->get()->toArray()['items'][0]['actor'];

    expect($renamed->id)->toBe($party->id)
        ->and($renamed->fresh()->data)->toMatchArray(['version' => 2, '$url' => 'https://example.com'])
        ->and($actor['label'])->toBe('Platform')
        ->and($actor['url'])->toBe('https://example.com');
});

it('updates and explicitly removes an external home on existing activities', function () {
    $party = Party::make('Studio', url: 'https://example.com/old');
    Storyfeed::activity('ping')->actor($party)->publish();

    Party::make('Studio', url: 'https://example.com/new');
    expect(Storyfeed::feed()->get()->toArray()['items'][0]['actor']['url'])->toBe('https://example.com/new');

    Party::make('Studio', url: null);
    expect(Storyfeed::feed()->get()->toArray()['items'][0]['actor']['url'])->toBeNull();
});

it('reads external homes in every role from retired party snapshots without party queries or writes', function () {
    $party = Party::make('Studio', url: 'https://example.com/studio');
    $activity = Storyfeed::activity('ping')->actor($party)->object($party)->to($party)->context($party)->publish();
    $party->delete();

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = strtolower($query->sql);
    });
    $item = Storyfeed::feed()->get()->toArray()['items'][0];
    $document = serialize_one($activity);

    foreach (['actor', 'object', 'target', 'context'] as $role) {
        expect($item[$role]['url'])->toBe('https://example.com/studio');
    }
    expect($document['actor']['url'])->toBe('https://example.com/studio')
        ->and($document['object']['url'])->toBe('https://example.com/studio');

    foreach ($queries as $sql) {
        expect($sql)->not->toContain('feed_parties')
            ->and($sql)->not->toMatch('/^\s*(insert|update|delete|replace)\b/');
    }
});
