<?php

use Illuminate\Support\HtmlString;
use Storyfeed\Contracts\FeedDetail;
use Storyfeed\Detail\Fields;

it('serializes with the reserved keys, so a stored row describes itself', function () {
    $detail = Fields::make(['Address' => '99.225.169.111']);
    $expected = [
        '$detail' => 'Storyfeed/Detail/Fields',
        '$v' => 1,
        'rows' => [['label' => 'Address', 'value' => '99.225.169.111', 'verbatim' => false, 'missing' => null]],
    ];

    expect($detail)->toBeInstanceOf(FeedDetail::class)
        ->and($detail->toArray())->toBe($expected)
        ->and($detail->toPayload())->toBe($expected);

    $stored = json_decode(json_encode($detail->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $props = array_diff_key($stored, array_flip([FeedDetail::KEY, FeedDetail::VERSION]));

    expect(Fields::upgrade($props, $stored[FeedDetail::VERSION]))->toBe(['rows' => $expected['rows']]);
});

it('accepts both shapes an app finds natural to write', function () {
    $map = Fields::make(['Address' => '1.1.1.1'])->toPayload()['rows'];
    $rows = Fields::make([['label' => 'Address', 'value' => '1.1.1.1']])->toPayload()['rows'];

    expect($rows)->toBe($map);
});

it('marks a value as compared rather than read', function () {
    $rows = Fields::make(['Browser' => Fields::verbatim('Mozilla/5.0')])->toPayload()['rows'];

    expect($rows[0]['verbatim'])->toBeTrue()
        ->and($rows[0]['value'])->toBe('Mozilla/5.0');
});

it('flattens an Htmlable, because a stored value has to survive a JSON column', function () {
    $rows = Fields::make(['Where' => new HtmlString('<b>Guelph</b>')])->toPayload()['rows'];

    expect($rows[0]['value'])->toBe('<b>Guelph</b>')
        ->and(json_decode(json_encode($rows[0]['value']) ?: '', true))->toBe('<b>Guelph</b>');
});

it('carries a missing sentence per row, because one payload holds two kinds of absence', function () {
    $rows = Fields::make([
        'Where' => ['value' => null, 'missing' => 'The address did not resolve to a place'],
        'Why' => null,
    ])->toPayload()['rows'];

    expect($rows[0]['missing'])->toBe('The address did not resolve to a place')
        ->and($rows[1]['missing'])->toBeNull();

    $default = Fields::make(['Why' => null], missing: 'Not recorded')->toPayload()['rows'];

    expect($default[0]['missing'])->toBe('Not recorded');
});

it('normalizes malformed stored rows and renders unknown versions without throwing', function () {
    foreach ([1, 0, 999] as $version) {
        expect(Fields::upgrade(['rows' => [['label' => 'A', 'value' => 1], 'junk', null]], $version))
            ->toBe(['rows' => [['label' => 'A', 'value' => 1]]]);

        foreach ([[], ['rows' => null], ['rows' => 'invalid']] as $payload) {
            expect(Fields::upgrade($payload, $version))->toBe(['rows' => []]);
        }
    }
});
