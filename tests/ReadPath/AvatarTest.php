<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;
use Storyfeed\Models\Party;

/*
 * The text avatar (issue #74): a model with no picture declares initials and
 * a disc colour beside the icon slot. The renderer draws the icon, else the
 * initials on the disc, else its own default, and picks the text colour.
 */

it('declares a text avatar through every construction path', function () {
    foreach ([new FeedMedia(initials: 'AC', color: '#438d98'), FeedMedia::make(initials: 'AC', color: '#438d98'), FeedMedia::make()->initials('AC')->color('#438d98')] as $media) {
        expect($media->initials)->toBe('AC')
            ->and($media->color)->toBe('#438d98')
            ->and($media->media())->toBe([
                'icon' => null, 'image' => null, 'preview' => null,
                'initials' => 'AC', 'color' => '#438d98',
                'files' => [],
            ]);
    }
});

it('carries the avatar text beside an icon, and either one alone is media', function () {
    expect(FeedMedia::make(icon: '/avatar.png')->initials('AC')->media())
        ->toMatchArray(['initials' => 'AC', 'color' => null])
        ->and(FeedMedia::make()->initials('AC')->media())->not->toBeNull()
        ->and(FeedMedia::make()->color('#438d98')->media())->not->toBeNull()
        ->and(FeedMedia::make(url: '/projects/1')->media())->toBeNull();
});

it('trims initials and treats blank as none', function () {
    expect(FeedMedia::make()->initials('  AC ')->initials)->toBe('AC')
        ->and(FeedMedia::make()->initials('   ')->initials)->toBeNull()
        ->and(FeedMedia::make()->initials('AC')->initials(null)->initials)->toBeNull();
});

it('stores the colour as lowercase #rrggbb and degrades anything else to null', function (?string $given, ?string $stored) {
    expect(FeedMedia::make()->color($given)->color)->toBe($stored);
})->with([
    ['#438D98', '#438d98'],
    ['438d98', '#438d98'],
    ['#4a9', '#44aa99'],
    [' #ABC ', '#aabbcc'],
    ['teal', null],
    ['#438d9', null],
    ['#438d98ff', null],
    ['rgb(1, 2, 3)', null],
    ['', null],
    [null, null],
]);

it('reaches the payload on the actor and stays out of the AS2 document', function () {
    $party = Party::make('Acme Co', data: ['$media' => ['initials' => 'AC', 'color' => '#438D98']]);
    $activity = Storyfeed::activity('ping')->actor($party)->publish();

    $actor = Storyfeed::feed()->get()->toArray()['items'][0]['actor'];

    expect($actor['media'])->toMatchArray(['icon' => null, 'initials' => 'AC', 'color' => '#438d98'])
        ->and(serialize_one($activity)['actor'])->not->toHaveKeys(['initials', 'color', 'icon']);
});

it('degrades a party avatar that is not text', function (array $media) {
    expect(Party::feedMedia(new FeedContext('storyfeed.party', data: ['$media' => $media])))->toBeNull();
})->with([
    [['initials' => ['A', 'C']]],
    [['color' => 0x438D98]],
]);
