<?php

use Storyfeed\FeedBuilder;
use Storyfeed\PendingActivity;
use Storyfeed\StoryfeedManager;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;

it('returns the manager with no arguments', function () {
    expect(storyfeed())->toBeInstanceOf(StoryfeedManager::class)
        ->and(storyfeed()->feed())->toBeInstanceOf(FeedBuilder::class);
});

it('returns a seeded pending activity when given a verb', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    $pending = storyfeed(ActivityVerb::Confirm, $delivery);

    expect($pending)->toBeInstanceOf(PendingActivity::class);

    $activity = $pending->publish();

    expect($activity->verb)->toBe('confirm')
        ->and($activity->object_id)->toEqual($delivery->id);
});

it('records through the helper', function () {
    $activity = storyfeed()->record('create', object: Delivery::create(['tracking_number' => 'TN-2']));

    expect($activity->verb)->toBe('create');
});
