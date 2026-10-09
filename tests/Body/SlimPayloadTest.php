<?php

use Storyfeed\Body\Component;
use Storyfeed\Body\Excerpt;
use Storyfeed\Body\FileAttachment;
use Storyfeed\Body\Image;
use Storyfeed\Body\ItemList;
use Storyfeed\Body\KeyValue;
use Storyfeed\Body\MediaObject;
use Storyfeed\Body\Prose;
use Storyfeed\FeedLink;
use Storyfeed\FeedResource;
use Storyfeed\MediaSlot;

/*
 * A body writes a setting only when it differs from its default, and its
 * upgrade() fills the default back in. Each case is a body as it is written
 * today beside the row the previous version wrote for the same body, in full:
 * a renderer must not be able to tell them apart.
 */
dataset('rows the previous version wrote', fn () => [
    'Excerpt' => [
        fn () => Excerpt::make('Fine by me.'),
        1, ['text' => 'Fine by me.', 'from' => null, 'truncated' => true],
    ],
    'Excerpt, whole and attributed' => [
        fn () => Excerpt::make('Fine by me.', from: 'Jasper', truncated: false),
        1, ['text' => 'Fine by me.', 'from' => 'Jasper', 'truncated' => false],
    ],
    'FileAttachment, empty' => [
        fn () => FileAttachment::make(),
        1, ['name' => null, 'size' => null, 'mediaType' => null],
    ],
    'FileAttachment' => [
        fn () => FileAttachment::make(4200, 'application/zip', 'archive.zip'),
        1, ['name' => 'archive.zip', 'size' => 4200, 'mediaType' => 'application/zip'],
    ],
    'Image, default slot' => [
        fn () => Image::make(),
        1, ['caption' => null, 'alt' => null, 'width' => null, 'height' => null, 'image' => 'preview'],
    ],
    'Image' => [
        fn () => Image::make('USS Butterscotch', 'A boat', 640, 480)->withIcon(),
        1, ['caption' => 'USS Butterscotch', 'alt' => 'A boat', 'width' => 640, 'height' => 480, 'image' => 'icon'],
    ],
    'Component, no props' => [
        fn () => Component::make('Common/ScoreCard'),
        1, ['name' => 'Common/ScoreCard', 'props' => []],
    ],
    'Component' => [
        fn () => Component::make('Common/ScoreCard', ['home' => 2]),
        1, ['name' => 'Common/ScoreCard', 'props' => ['home' => 2]],
    ],
    'ItemList' => [
        fn () => ItemList::make(['Rice']),
        1, ['title' => null, 'ordered' => false, 'items' => ['Rice'], 'totalItems' => null, 'more' => null],
    ],
    'ItemList, ordered with more' => [
        fn () => ItemList::ordered(['Soak', FeedLink::make('Simmer', '/simmer')], 'Method', 9, FeedLink::make('All nine', '/all')),
        1, ['title' => 'Method', 'ordered' => true, 'items' => ['Soak', ['label' => 'Simmer', 'href' => '/simmer']], 'totalItems' => 9, 'more' => ['label' => 'All nine', 'href' => '/all']],
    ],
    'KeyValue' => [
        fn () => KeyValue::make(['Carrier' => 'UPS', 'Tracking' => KeyValue::verbatim('1Z999')]),
        2, ['title' => null, 'defaultPlaceholder' => null, 'items' => [
            ['key' => 'Carrier', 'value' => 'UPS', 'verbatim' => false, 'placeholder' => null],
            ['key' => 'Tracking', 'value' => '1Z999', 'verbatim' => true, 'placeholder' => null],
        ]],
    ],
    'KeyValue, with placeholders' => [
        fn () => KeyValue::make([
            'Seat' => null,
            'Table' => KeyValue::placeholder(null, 'not seated'),
            'Silent' => ['value' => null, 'placeholder' => null],
            'Same' => KeyValue::placeholder(null, 'unknown'),
        ], title: 'Booking', defaultPlaceholder: 'unknown'),
        2, ['title' => 'Booking', 'defaultPlaceholder' => 'unknown', 'items' => [
            ['key' => 'Seat', 'value' => null, 'verbatim' => false, 'placeholder' => 'unknown'],
            ['key' => 'Table', 'value' => null, 'verbatim' => false, 'placeholder' => 'not seated'],
            ['key' => 'Silent', 'value' => null, 'verbatim' => false, 'placeholder' => null],
            ['key' => 'Same', 'value' => null, 'verbatim' => false, 'placeholder' => 'unknown'],
        ]],
    ],
    'MediaObject, empty' => [
        fn () => MediaObject::make(),
        2, ['subject' => null, 'content' => null, 'image' => null, 'files' => [], 'footnote' => null],
    ],
    'MediaObject' => [
        fn () => MediaObject::make(FeedLink::make('N201'), 'Basmati.', MediaSlot::Icon, [FeedResource::make('/a.pdf', 'application/pdf', 'a.pdf')], 'Approved'),
        2, ['subject' => ['label' => 'N201', 'href' => null], 'content' => 'Basmati.', 'image' => 'icon', 'files' => [
            ['type' => 'Document', 'href' => '/a.pdf', 'mediaType' => 'application/pdf', 'name' => 'a.pdf'],
        ], 'footnote' => 'Approved'],
    ],
    'Prose' => [
        fn () => Prose::make('Basmati replaces Jasmine.'),
        1, ['content' => 'Basmati replaces Jasmine.', 'mediaType' => 'text/plain', 'verbatim' => false, 'title' => null],
    ],
    'Prose, code' => [
        fn () => Prose::code('SELECT 1', 'text/x-sql', 'report.sql'),
        1, ['content' => 'SELECT 1', 'mediaType' => 'text/x-sql', 'verbatim' => true, 'title' => 'report.sql'],
    ],
]);

it('renders a slim row exactly as the full row the previous version wrote', function (Closure $body, int $version, array $previous) {
    $body = $body();

    expect($body::version())->toBeGreaterThan($version)
        ->and(rendered($body))->toBe($body::upgrade($previous, $version));
})->with('rows the previous version wrote');

it('writes nothing but the envelope and the data for a body left at its defaults', function () {
    expect(Excerpt::make('A')->toPayload())->toBe(['$body' => Excerpt::bodyType(), '$v' => 2, 'text' => 'A'])
        ->and(FileAttachment::make()->toPayload())->toBe(['$body' => FileAttachment::bodyType(), '$v' => 2])
        ->and(Image::make()->toPayload())->toBe(['$body' => Image::bodyType(), '$v' => 2])
        ->and(Component::make('Card')->toPayload())->toBe(['$body' => Component::bodyType(), '$v' => 2, 'name' => 'Card'])
        ->and(ItemList::make(['a'])->toPayload())->toBe(['$body' => ItemList::bodyType(), '$v' => 2, 'items' => ['a']])
        ->and(KeyValue::make(['A' => 1])->toPayload())->toBe(['$body' => KeyValue::bodyType(), '$v' => 3, 'items' => [['key' => 'A', 'value' => 1]]])
        ->and(MediaObject::make()->toPayload())->toBe(['$body' => MediaObject::bodyType(), '$v' => 3])
        ->and(Prose::make('A')->toPayload())->toBe(['$body' => Prose::bodyType(), '$v' => 2, 'content' => 'A']);
});

it('keeps a data field a reader expects, even when it is empty', function () {
    expect(ItemList::make()->toPayload())->toHaveKey('items')
        ->and(KeyValue::make()->toPayload())->toHaveKey('items')
        ->and(KeyValue::make(['Seat' => null])->toPayload()['items'][0])->toBe(['key' => 'Seat', 'value' => null])
        ->and(Excerpt::make('')->toPayload())->toHaveKey('text')
        ->and(Prose::make('')->toPayload())->toHaveKey('content');
});

it('slims the three-fact KeyValue the issue measured', function () {
    $body = KeyValue::make(['Carrier' => 'UPS', 'Tracking' => KeyValue::verbatim('1Z999'), 'Service' => 'Ground']);

    expect(json_encode($body->toPayload()))->toBe(
        '{"$body":"Storyfeed\/Body\/KeyValue","$v":3,"items":[{"key":"Carrier","value":"UPS"},{"key":"Tracking","value":"1Z999","verbatim":true},{"key":"Service","value":"Ground"}]}',
    );
});
