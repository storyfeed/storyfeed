<?php

use Illuminate\Support\Carbon;
use Storyfeed\Support\Chronology;

it('formats the same UTC timestamp as Carbon without mutating its source', function (string $date, string $zone) {
    $value = Carbon::parse($date, $zone);
    $original = $value->format('Y-m-d H:i:s.u e');
    expect(Chronology::iso($value))->toBe($value->toISOString())
        ->and($value->format('Y-m-d H:i:s.u e'))->toBe($original);
})->with([
    ['2026-10-07 12:30:45.123456', 'America/Toronto'],
    ['2026-03-08 01:59:59.999999', 'America/Toronto'],
    ['2026-11-01 01:30:00.000001', 'America/Toronto'],
    ['0000-01-01 00:00:00', 'UTC'],
    ['9999-12-31 23:59:59.999999', 'UTC'],
    ['+10000-01-01 00:00:00', 'UTC'],
    ['-0001-01-01 00:00:00', 'UTC'],
]);

it('keeps an absent date absent', function () {
    expect(Chronology::iso(null))->toBeNull();
});
