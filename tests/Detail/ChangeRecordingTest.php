<?php

use Storyfeed\Detail\Change;
use Storyfeed\Facades\Storyfeed;

it('records and reads a detail exactly like its plain array without writing read upgrades back', function () {
    $changes = ['Status' => ['Draft', 'Ready'], 'Added' => [1 => null], 'Removed' => [0 => 'old']];
    $detail = Change::make($changes);
    $plain = ['$detail' => 'Storyfeed/Detail/Change', '$v' => 1, 'items' => $changes];
    $data = ['reason' => 'Reviewed', 'diff' => $plain, '$app' => ['keep' => true]];
    $authored = Storyfeed::activity('revise')->data(array_replace($data, ['diff' => $detail->toArray()]))->publish();
    $manual = Storyfeed::activity('revise')->data($data)->publish();
    $bytes = $authored->fresh()->getRawOriginal('data');

    expect($authored->fresh()->data)->toBe($data)
        ->and($manual->fresh()->data)->toBe($data)
        ->and($manual->fresh()->getRawOriginal('data'))->toBe($bytes);

    $items = Storyfeed::feed()->log()->get()->toArray()['items'];
    expect($items)->toHaveCount(2);

    foreach ($items as $item) {
        expect($item['data'])->toBe($data);
        $stored = $item['data']['diff'];
        $props = array_diff_key($stored, array_flip([Change::KEY, Change::VERSION]));
        expect(Change::upgrade($props, $stored[Change::VERSION] ?? 1))->toBe(['items' => $changes]);
    }

    expect($authored->fresh()->getRawOriginal('data'))->toBe($bytes)
        ->and($manual->fresh()->getRawOriginal('data'))->toBe($bytes);
});
