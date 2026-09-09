<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Batch;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

// Laravel offers Date::use(CarbonImmutable::class) as a supported app-wide
// setting, and apps that take it get CarbonImmutable back from every model
// date cast. CarbonImmutable does not extend Illuminate\Support\Carbon, so
// anywhere the package type-hints the concrete class it rejects the value.
beforeEach(function () {
    Date::use(CarbonImmutable::class);
});

afterEach(function () {
    Date::useDefault();
});

it('publishes when the application has opted into immutable dates', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    Storyfeed::activity()
        ->actor($sally)
        ->verb('upload', Delivery::create(['tracking_number' => 'TN-1']))
        ->publish();

    expect(Activity::query()->count())->toBe(1);
});

it('assigns a burst to one batch when dates are immutable', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()
            ->actor($sally)
            ->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))
            ->publish();
    }

    $batch = Batch::query()->sole();

    expect($batch->activities_count)->toBe(3)
        ->and($batch->isOpen())->toBeTrue();
});

it('reads a feed back when dates are immutable', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()
            ->actor($sally)
            ->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))
            ->publish();
    }

    // Curation collapses the burst, so the assertion is that the read path
    // survives immutable dates at all — cursors, windows and comparisons.
    $items = Storyfeed::feed()->limit(10)->get()->items();

    expect($items)->toHaveCount(1)
        ->and($items[0]['count'])->toBe(3);
});

it('closes a stale batch when the actor returns, with immutable dates', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    Storyfeed::activity()
        ->actor($sally)
        ->verb('upload', Delivery::create(['tracking_number' => 'TN-1']))
        ->publishedAt(Date::now()->subHours(2))
        ->publish();

    Storyfeed::activity()
        ->actor($sally)
        ->verb('upload', Delivery::create(['tracking_number' => 'TN-2']))
        ->publish();

    expect(Batch::query()->count())->toBe(2)
        ->and(Batch::query()->whereNotNull('closed_at')->count())->toBe(1);
});
