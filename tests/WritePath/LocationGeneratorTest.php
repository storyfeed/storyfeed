<?php

use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Sources\Entry;
use Storyfeed\Support\Chronology;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

it('records where an act happened with at(), and what produced it with generator()', function () {
    $talk = Customer::create(['name' => 'GPUG Waterloo']);
    Story::fallback()->headline(':actor unveiled :object at :location from :generator');

    $activity = Storyfeed::activity('unveil', 'Storyfeed')->by('Jasper')->at($talk)->generator('Claude')->publish();
    $node = app(NodePresenter::class)->activityNode($activity->fresh());

    expect($activity->location->is($talk))->toBeTrue()
        ->and($activity->generator->name)->toBe('Claude')
        ->and($node['location']['label'])->toBe('GPUG Waterloo')
        ->and($node['generator']['label'])->toBe('Claude')
        ->and($node['headline_template'])->toBe(':actor unveiled :object at :location from :generator')
        ->and(serialize_one($activity)['summary'])->toBe('Jasper unveiled Storyfeed at GPUG Waterloo from Claude')
        ->and(serialize_one($activity)['location']['name'])->toBe('GPUG Waterloo')
        ->and(serialize_one($activity)['generator']['name'])->toBe('Claude')
        ->and(Storyfeed::feed()->location($talk)->log()->get()->toArray())->toHaveCount(1)
        ->and(Storyfeed::feed()->generator('Claude')->log()->get()->toArray())->toHaveCount(1)
        ->and(Storyfeed::feed()->locationType(Customer::class)->log()->get()->toArray())->toHaveCount(1)
        ->and(Storyfeed::feed()->involving($talk)->log()->get()->toArray())->toHaveCount(1);
});

it('takes location and generator through record() and enum verbs', function () {
    $delivery = Delivery::create(['tracking_number' => 'W78']);

    $records = [
        Storyfeed::record('confirm', object: $delivery, location: 'Dock 4', generator: 'Scanner app'),
        ActivityVerb::Confirm->record(object: $delivery, location: 'Dock 4', generator: 'Scanner app'),
        ActivityVerb::Confirm->at('Dock 4')->generator('Scanner app')->publish(),
    ];

    foreach ($records as $record) {
        expect($record->location->name)->toBe('Dock 4')
            ->and($record->generator->name)->toBe('Scanner app');
    }
});

it('keeps the derived id of a source item that names neither new role', function () {
    $item = Entry::make('release', '2026-10-09 12:00:00', actor: 'Storyfeed', object: ['type' => 'release', 'label' => 'v0.20.0']);

    // The id as it was derived before location and generator existed.
    $roles = array_fill_keys(['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'], null);
    $roles['actor'] = ['storyfeed.entity', 'storyfeed'];
    $roles['object'] = ['release', 'v0.20.0'];
    $before = substr(hash('sha256', json_encode(['release', Chronology::stamp($item->publishedAt), $roles, []], JSON_THROW_ON_ERROR)), 0, 26);

    expect($item->identity())->toBe($before)
        ->and(Entry::make('release', '2026-10-09 12:00:00', actor: 'Storyfeed', object: ['type' => 'release', 'label' => 'v0.20.0'], location: 'Waterloo')->identity())
        ->not->toBe($before);
});
