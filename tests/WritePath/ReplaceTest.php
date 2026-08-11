<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Delivery;

it('collapses repeated same-object same-verb activities with publishAndReplace', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity('save', $delivery)->publishAndReplace();
    Storyfeed::activity('save', $delivery)->publishAndReplace();
    $latest = Storyfeed::activity('save', $delivery)->publishAndReplace();

    $remaining = Activity::query()->object($delivery)->verb('save')->get();

    expect($remaining)->toHaveCount(1)
        ->and($remaining->first()->id)->toBe($latest->id);
});

it('does not replace activities with a different verb', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity('create', $delivery)->publish();
    Storyfeed::activity('save', $delivery)->publishAndReplace();

    expect(Activity::query()->object($delivery)->count())->toBe(2);
});
