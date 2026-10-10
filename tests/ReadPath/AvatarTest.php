<?php

use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\FeedImage;
use Storyfeed\FeedMedia;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Party;
use Storyfeed\Support\Avatar;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

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
                'slots' => [],
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

/*
 * Every entity has an avatar (#92): declared icon, else declared initials
 * and colour, else initials from the label on a colour from the identity.
 */

it('derives initials from the first and last words of the label', function (?string $label, string $initials) {
    expect(Avatar::initials($label))->toBe($initials);
})->with([
    ['Acme Co', 'AC'],
    ['Acme', 'A'],
    ['  Ana   de la Cruz ', 'AC'],
    ['@ana smith', 'AS'],
    ['élan vital', 'ÉV'],
    ['Row 3', 'R3'],
    ['— —', '?'],
    ['', '?'],
    [null, '?'],
]);

it('hashes an identity onto the palette, the same on every request', function () {
    $customer = Customer::create(['name' => 'Acme Co']);
    Storyfeed::activity('onboard', $customer)->anonymously()->publish();

    $first = Storyfeed::feed()->get()->toArray()['items'][0]['object']['media'];
    $second = Storyfeed::feed()->log()->get()->toArray()['items'][0]['object']['media'];

    expect($first)->toMatchArray(['icon' => null, 'initials' => 'AC', 'color' => Avatar::color('customer', (string) $customer->id)])
        ->and($second)->toBe($first)
        ->and(Avatar::PALETTE)->toContain($first['color'])
        // The palette and the hash are contract: kits and pages agree on a colour.
        ->and(Avatar::color('customer', '1'))->toBe(Avatar::PALETTE[crc32("customer\x001") % count(Avatar::PALETTE)]);
});

it('keeps declared values, deriving only what is missing, and derives nothing beside an icon', function () {
    $fill = fn (array $declared) => Avatar::fill([...Avatar::fill(null, 't', '1', 'x'), 'initials' => null, 'color' => null, ...$declared], 'customer', '1', 'Acme Co');

    expect($fill(['initials' => 'ZZ', 'color' => '#000000']))->toMatchArray(['initials' => 'ZZ', 'color' => '#000000'])
        ->and($fill(['initials' => 'ZZ']))->toMatchArray(['initials' => 'ZZ', 'color' => Avatar::color('customer', '1')])
        ->and($fill(['color' => '#000000']))->toMatchArray(['initials' => 'AC', 'color' => '#000000'])
        ->and($fill(['icon' => FeedImage::make('/a.png')->toArray()]))->toMatchArray(['initials' => null, 'color' => null]);
});

it('gives a party the same rule, by its key', function () {
    Storyfeed::activity('ping')->actor('Courier Bot')->publish();

    expect(Storyfeed::feed()->get()->toArray()['items'][0]['actor']['media'])
        ->toMatchArray(['initials' => 'CB', 'color' => Avatar::color('storyfeed.party', 'courier-bot')]);
});

it('draws a tombstone on the neutral colour with its stored label', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::activity('ship', $delivery)->anonymously()->publish();
    $delivery->delete();

    $object = fn () => Storyfeed::feed()->get()->toArray()['items'][0]['object'];

    // No label kept: `?` on the neutral colour.
    expect($object()['tombstone'])->not->toBeNull()
        ->and($object()['media'])->toMatchArray(['initials' => '?', 'color' => Avatar::NEUTRAL]);

    $tombstone = FeedTombstone::sole();
    $tombstone->forceFill(['label' => 'Delivery #TN-1'])->save();
    (new SnapshotEntity)($tombstone);

    expect($object()['media'])->toMatchArray(['initials' => 'DT', 'color' => Avatar::NEUTRAL]);
});

it('keeps the derived avatar off the Activity Streams wire', function () {
    $activity = Storyfeed::activity('onboard', Customer::create(['name' => 'Acme Co']))->anonymously()->publish();

    expect(json_encode(serialize_one($activity)))->not->toContain('initials')->not->toContain(Avatar::color('customer', (string) $activity->object_id));
});
