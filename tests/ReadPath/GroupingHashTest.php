<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\NullStrategy;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('writes candidate hashes for every applicable axis at publish', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    $activity = Storyfeed::activity()->actor($user)->confirm($delivery)->for($customer)->publish();

    $buckets = Grouping::query()
        ->where('activity_id', $activity->id)
        ->pluck('hash', 'bucket');

    expect($buckets)->toHaveKeys(['repeat', 'actors', 'targets']);
});

it('omits the actors axis when there is no target', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    $activity = Storyfeed::activity()->actor($user)->confirm($delivery)->publish();

    $buckets = Grouping::query()->where('activity_id', $activity->id)->pluck('bucket');

    expect($buckets->all())->not->toContain('actors')
        ->and($buckets->all())->toContain('repeat', 'targets');
});

it('produces identical repeat hashes for same actor, verb, object type, and day', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $a = Storyfeed::activity()->actor($user)->upload(Delivery::create(['tracking_number' => 'A']))->publish();
    $b = Storyfeed::activity()->actor($user)->upload(Delivery::create(['tracking_number' => 'B']))->publish();

    $hashA = Grouping::query()->where('activity_id', $a->id)->where('bucket', 'repeat')->value('hash');
    $hashB = Grouping::query()->where('activity_id', $b->id)->where('bucket', 'repeat')->value('hash');

    expect($hashA)->toBe($hashB);
});

it('writes no hashes with the null strategy', function () {
    config()->set('storyfeed.grouping.strategy', NullStrategy::class);

    $activity = Storyfeed::activity()->verb('ping')->publish();

    expect(Grouping::query()->where('activity_id', $activity->id)->count())->toBe(0);
});
