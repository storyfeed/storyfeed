<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\Models\Party;

it('reads reserved party pictures from snapshots without linking or querying the party', function () {
    $party = Party::make('Studio', data: [
        'media' => ['app' => 'owned'],
        '$media' => [
            'icon' => '/avatar.svg',
            'preview' => ['src' => '/thumb.svg', 'width' => 64, 'height' => 64, 'mediaType' => 'image/svg+xml', 'alt' => 'Studio'],
            'image' => ['src' => '/large.svg', 'width' => -1],
        ],
    ]);
    Storyfeed::activity('ping')->actor($party)->publish();
    $party->delete();

    $actor = Storyfeed::feed()->get()->toArray()['items'][0]['actor'];

    expect($actor['url'])->toBeNull()
        ->and($actor['label'])->toBe('Studio')
        ->and($actor['data']['media'])->toBe(['app' => 'owned'])
        ->and($actor['media']['icon']['src'])->toBe('/avatar.svg')
        ->and($actor['media']['preview'])->toBe([
            'src' => '/thumb.svg', 'mediaType' => 'image/svg+xml', 'width' => 64, 'height' => 64, 'alt' => 'Studio',
        ])
        ->and($actor['media']['image']['width'])->toBeNull();
});

it('degrades malformed party media without throwing', function ($value) {
    expect(Party::feedMedia(new FeedContext('storyfeed.party', data: ['$media' => $value])))->toBeNull();
})->with([
    'null' => [null], 'scalar' => [42], 'bare descriptor string' => ['/image.svg'],
    'empty' => [[]], 'unknown slot' => [['video' => '/v']], 'null slots' => [['icon' => null]],
    'empty src' => [['preview' => '  ']], 'no src' => [['preview' => ['alt' => 'x']]],
    'array src' => [['icon' => ['src' => []]]], 'wrong dimensions' => [['image' => ['src' => '/x', 'width' => '64']]],
    'wrong alt' => [['icon' => ['src' => '/x', 'alt' => false]]],
    'unknown image field' => [['icon' => ['src' => '/x', 'extra' => true]]],
    'mixed valid invalid' => [['icon' => '/x', 'preview' => false]],
]);

it('does not interpret an app media key or an absent reserved key', function () {
    expect(Party::feedMedia(new FeedContext('storyfeed.party')))->toBeNull()
        ->and(Party::feedMedia(new FeedContext('storyfeed.party', data: ['media' => ['icon' => '/x']])))->toBeNull();
});

it('updates pictures for existing activities when the party snapshot changes', function () {
    $party = Party::make('Studio', data: ['$media' => ['preview' => '/old.svg']]);
    Storyfeed::activity('ping')->object($party)->publish();
    Party::make('Studio', data: ['$media' => ['preview' => '/new.svg']]);
    expect(Storyfeed::feed()->get()->toArray()['items'][0]['object']['media']['preview']['src'])->toBe('/new.svg');
});
