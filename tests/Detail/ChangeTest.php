<?php

use Storyfeed\Contracts\FeedDetail;
use Storyfeed\Detail\Change;

it('round-trips authored changes with their form and version intact', function () {
    $changes = ['Status' => ['Draft', 'Ready']];
    $detail = Change::make($changes);
    $expected = ['$detail' => 'Storyfeed/Detail/Change', '$v' => 1, 'changes' => $changes];

    expect($detail)->toBeInstanceOf(FeedDetail::class)
        ->and($detail->toArray())->toBe($expected)
        ->and($detail->toPayload())->toBe($expected);

    $stored = json_decode(json_encode($detail->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $props = array_diff_key($stored, array_flip([FeedDetail::KEY, FeedDetail::VERSION]));
    $upgraded = Change::upgrade($props, $stored[FeedDetail::VERSION]);

    expect($upgraded)->toBe(['changes' => $changes])
        ->and(Change::make($upgraded['changes'])->toPayload())->toBe($expected);
});

it('reads a missing version as version one without changing the stored payload', function () {
    $stored = ['$detail' => Change::name(), 'changes' => ['Status' => ['Draft', 'Ready']]];
    $original = json_encode($stored, JSON_THROW_ON_ERROR);
    $props = array_diff_key($stored, array_flip([FeedDetail::KEY, FeedDetail::VERSION]));

    expect(Change::upgrade($props, $stored[FeedDetail::VERSION] ?? 1))->toBe(['changes' => $stored['changes']])
        ->and(json_encode($stored, JSON_THROW_ON_ERROR))->toBe($original);
});

it('distinguishes absent sides from explicit null and preserves field order through JSON', function () {
    $changes = [
        'Zulu added' => [1 => null],
        'Alpha removed' => [0 => null],
        'Nullable' => [null, 'Ready'],
        'Scalar types' => [false, 42],
        'Float' => [1.5, true],
    ];
    $stored = json_decode(json_encode(Change::make($changes)->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

    expect($stored['changes'])->toBe($changes)
        ->and(array_keys($stored['changes']))->toBe(array_keys($changes))
        ->and(Change::upgrade(['changes' => $stored['changes']], 1))->toBe(['changes' => $changes]);
});

it('allows an empty change map', function () {
    expect(Change::make([])->toPayload())->toBe(['$detail' => Change::name(), '$v' => 1, 'changes' => []])
        ->and(Change::upgrade(['changes' => []], 1))->toBe(['changes' => []]);
});

it('rejects non-scalar values and malformed pairs without coercing them', function () {
    $resource = fopen('php://memory', 'r');

    try {
        foreach ([[], new stdClass, Change::make([]), $resource, INF, NAN] as $value) {
            foreach ([[$value, 'after'], ['before', $value]] as $pair) {
                expect(fn () => Change::make(['Field' => $pair]))->toThrow(InvalidArgumentException::class);
            }
        }

        foreach ([[], 'value', [2 => 'value'], ['before' => 'value'], ['a', 'b', 'c']] as $pair) {
            expect(fn () => Change::make(['Field' => $pair]))->toThrow(InvalidArgumentException::class);
        }

        expect(fn () => Change::make([42 => ['a', 'b']]))->toThrow(InvalidArgumentException::class);
    } finally {
        fclose($resource);
    }
});

it('normalizes malformed stored changes and renders unknown versions as empty', function () {
    foreach ([[], ['changes' => null], ['changes' => 'invalid'], ['changes' => new stdClass]] as $payload) {
        expect(Change::upgrade($payload, 1))->toBe(['changes' => []]);
    }

    $payload = ['changes' => ['Good' => ['a', 'b'], 'Bad' => [[], 'b'], 'Empty' => []], 'extra' => 'ignored'];
    expect(Change::upgrade($payload, 1))->toBe(['changes' => ['Good' => ['a', 'b']]]);

    foreach ([0, -1, 2, 999] as $version) {
        expect(Change::upgrade($payload, $version))->toBe(['changes' => []]);
    }
});
