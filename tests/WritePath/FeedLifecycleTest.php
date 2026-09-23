<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

it('keeps the activities of a deleted model, pointed at its tombstone', function () {
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['customer_id' => $customer->id, 'tracking_number' => 'TN-1']);

    $activity = Storyfeed::activity('confirm', $delivery)->for($customer)->publish();
    Storyfeed::activity()->verb('ping')->publish();

    $delivery->delete();

    $tombstone = FeedTombstone::sole();

    expect(Activity::query()->count())->toBe(2)
        ->and($activity->fresh()->object_type)->toBe('storyfeed.tombstone')
        ->and($activity->fresh()->object_id)->toBe($tombstone->id)
        ->and($tombstone->restorable)->toBeTrue();
});

it('keeps the activities of a force-deleted model, and its tombstone is permanent', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity('confirm', $delivery)->publish();

    $delivery->forceDelete();

    expect(Activity::query()->count())->toBe(1)
        ->and(FeedTombstone::sole()->restorable)->toBeFalse();
});

it('makes the tombstone permanent when a trashed model is force-deleted', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity('confirm', $delivery)->publish();

    $delivery->delete();

    expect(FeedTombstone::sole()->restorable)->toBeTrue();

    $delivery->forceDelete();

    expect(Activity::query()->count())->toBe(1)
        ->and(FeedTombstone::sole()->restorable)->toBeFalse();
});

it('still soft-deletes a model\'s activities when asked with deleteFromFeed()', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity('confirm', $delivery)->publish();
    Storyfeed::activity()->verb('ping')->publish();

    $delivery->deleteFromFeed();

    expect(Activity::query()->count())->toBe(1)
        ->and(Activity::query()->withTrashed()->count())->toBe(2);
});

it('erases activities, grouping and participant rows when asked with forceDeleteFromFeed()', function () {
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['customer_id' => $customer->id, 'tracking_number' => 'TN-1']);

    $live = Storyfeed::activity('confirm', $delivery)->for($customer)->publish();
    $trashed = Storyfeed::activity('dispatch', $delivery)->for($customer)->publish();
    $trashed->delete();
    $other = Storyfeed::activity()->verb('ping')->publish();

    $participants = config('storyfeed.tables.participants', 'feed_participants');

    expect(Grouping::query()->whereIn('activity_id', [$live->id, $trashed->id])->count())->toBeGreaterThan(0)
        ->and(DB::table($participants)->whereIn('activity_id', [$live->id, $trashed->id])->count())->toBeGreaterThan(0);

    $delivery->forceDeleteFromFeed();

    expect(Activity::query()->withTrashed()->pluck('id')->all())->toBe([$other->id])
        ->and(Grouping::query()->whereIn('activity_id', [$live->id, $trashed->id])->count())->toBe(0)
        ->and(DB::table($participants)->whereIn('activity_id', [$live->id, $trashed->id])->count())->toBe(0)
        ->and(Grouping::query()->where('activity_id', $other->id)->count())->toBeGreaterThan(0);
});
