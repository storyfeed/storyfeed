<?php

use Illuminate\Support\HtmlString;
use Storyfeed\Body\KeyValue;
use Storyfeed\Contracts\FeedBody;

it('serializes with the reserved keys, so a stored row describes itself', function () {
    $body = KeyValue::make(['Address' => '99.225.169.111']);
    $expected = [
        '$body' => 'Storyfeed/Body/KeyValue',
        '$v' => 2,
        'title' => null, 'defaultPlaceholder' => null,
        'items' => [['key' => 'Address', 'value' => '99.225.169.111', 'verbatim' => false, 'placeholder' => null]],
    ];

    expect($body)->toBeInstanceOf(FeedBody::class)
        ->and($body->toArray())->toBe($expected)
        ->and($body->toPayload())->toBe($expected);

    $stored = json_decode(json_encode($body->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $props = array_diff_key($stored, array_flip([FeedBody::KEY, FeedBody::VERSION]));

    expect(KeyValue::upgrade($props, $stored[FeedBody::VERSION]))->toBe(['title' => null, 'defaultPlaceholder' => null, 'items' => $expected['items']]);
});

it('accepts both shapes an app finds natural to write', function () {
    $map = KeyValue::make(['Address' => '1.1.1.1'])->toPayload()['items'];
    $rows = KeyValue::make([['key' => 'Address', 'value' => '1.1.1.1']])->toPayload()['items'];

    expect($rows)->toBe($map);
});

it('marks a value as compared rather than read', function () {
    $rows = KeyValue::make(['Browser' => KeyValue::verbatim('Mozilla/5.0')])->toPayload()['items'];

    expect($rows[0]['verbatim'])->toBeTrue()
        ->and($rows[0]['value'])->toBe('Mozilla/5.0');
});

it('flattens an Htmlable, because a stored value has to survive a JSON column', function () {
    $rows = KeyValue::make(['Where' => new HtmlString('<b>Guelph</b>')])->toPayload()['items'];

    expect($rows[0]['value'])->toBe('<b>Guelph</b>')
        ->and(json_decode(json_encode($rows[0]['value']) ?: '', true))->toBe('<b>Guelph</b>');
});

it('carries a missing sentence per row, because one payload holds two kinds of absence', function () {
    $rows = KeyValue::make([
        'Where' => ['value' => null, 'placeholder' => 'The address did not resolve to a place'],
        'Why' => null,
    ])->toPayload()['items'];

    expect($rows[0]['placeholder'])->toBe('The address did not resolve to a place')
        ->and($rows[1]['placeholder'])->toBeNull();

    $default = KeyValue::make(['Why' => null], defaultPlaceholder: 'Not recorded')->toPayload()['items'];

    expect($default[0]['placeholder'])->toBe('Not recorded');
});

it('normalizes malformed stored rows and renders unknown versions without throwing', function () {
    foreach ([1, 0, 999] as $version) {
        expect(KeyValue::upgrade(['items' => [['key' => 'A', 'value' => 1], 'junk', null]], $version))
            ->toBe(['title' => null, 'defaultPlaceholder' => null, 'items' => [['key' => 'A', 'value' => 1, 'placeholder' => null]]]);

        foreach ([[], ['items' => null], ['items' => 'invalid']] as $payload) {
            expect(KeyValue::upgrade($payload, $version))->toBe(['title' => null, 'defaultPlaceholder' => null, 'items' => []]);
        }
    }
});

it('carries a title above the pairs, and omits it when there is none', function () {
    $titled = KeyValue::make(['Pickup' => '7:00 pm'], title: 'Order #1042')->toPayload();
    $plain = KeyValue::make(['Pickup' => '7:00 pm'])->toPayload();

    expect($titled['title'])->toBe('Order #1042')
        ->and($plain['title'])->toBeNull();
});

it('gives one absence its own word without dropping into the payload shape', function () {
    $items = KeyValue::make([
        'Table' => KeyValue::placeholder(null, 'not seated'),
        'Pickup' => '7:00 pm',
    ])->toPayload()['items'];

    expect($items[0])->toBe(['key' => 'Table', 'value' => null, 'verbatim' => false, 'placeholder' => 'not seated'])
        ->and($items[1]['placeholder'])->toBeNull();
});

it('upgrades stored v1 placeholders without changing the stored payload', function () {
    $stored = [
        'missing' => 'Unknown',
        'items' => [
            ['key' => 'Default', 'value' => null],
            ['key' => 'Own', 'value' => null, 'missing' => 'Not seated'],
            ['key' => 'Silent', 'value' => null, 'missing' => null],
            ['key' => 'New wins', 'value' => null, 'missing' => 'Old', 'placeholder' => null],
            ['key' => 'Malformed', 'value' => null, 'missing' => []],
            ['key' => 'Zero', 'value' => 0],
            ['key' => 'False', 'value' => false],
        ],
    ];
    $original = $stored;
    $upgraded = KeyValue::upgrade($stored, 1);

    expect($upgraded['defaultPlaceholder'])->toBe('Unknown')
        ->and(array_column($upgraded['items'], 'placeholder'))->toBe(['Unknown', 'Not seated', null, null, null, 'Unknown', 'Unknown'])
        ->and($upgraded['items'][5]['value'])->toBe(0)
        ->and($upgraded['items'][6]['value'])->toBeFalse()
        ->and($upgraded)->not->toHaveKey('missing')
        ->and(array_filter($upgraded['items'], fn (array $row) => array_key_exists('missing', $row)))->toBe([])
        ->and($stored)->toBe($original)
        ->and(KeyValue::upgrade($upgraded, 2))->toBe($upgraded);
});

it('preserves an explicit null over a body default in new and stored payloads', function () {
    $body = KeyValue::make([
        'Silent' => ['value' => null, 'placeholder' => null],
        'Default' => null,
        'Own' => KeyValue::placeholder(null, 'Not seated'),
    ], defaultPlaceholder: 'Unknown');

    expect($body->toPayload()['defaultPlaceholder'])->toBe('Unknown')
        ->and(array_column($body->toPayload()['items'], 'placeholder'))->toBe([null, 'Unknown', 'Not seated'])
        ->and(KeyValue::upgrade(['missing' => 'Old', 'defaultPlaceholder' => null, 'items' => [['key' => 'A', 'value' => null]]], 1)['items'][0]['placeholder'])->toBeNull()
        ->and(method_exists(KeyValue::class, 'missing'))->toBeFalse()
        ->and(method_exists(KeyValue::class, 'missingAs'))->toBeFalse();
});
