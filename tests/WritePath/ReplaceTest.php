<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
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

it('soft-deletes superseded rows by default: history is kept, the feed forgets', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    $first = Storyfeed::activity('save', $delivery)->publishAndReplace();
    $latest = Storyfeed::activity('save', $delivery)->publishAndReplace();

    $trashed = Activity::query()->withTrashed()->whereKey($first->id)->first();

    expect($trashed)->not->toBeNull()
        ->and($trashed->trashed())->toBeTrue()
        ->and(Activity::query()->object($delivery)->verb('save')->pluck('id')->all())->toBe([$latest->id])
        // The participant index is over rows that exist; the superseded row
        // must not be findable by the entity it involved.
        ->and(DB::table(SyncParticipants::table())->where('activity_id', $first->id)->count())->toBe(0)
        // Grouping rows stay: inert, and prune sweeps them with the activity.
        ->and(Grouping::query()->where('activity_id', $first->id)->count())->toBeGreaterThan(0);
});

it('hard-deletes superseded rows and everything pointing at them when replace.delete is force', function () {
    config()->set('storyfeed.replace.delete', 'force');

    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    $first = Storyfeed::activity('save', $delivery)->publishAndReplace();
    $second = Storyfeed::activity('save', $delivery)->publishAndReplace();
    $latest = Storyfeed::activity('save', $delivery)->publishAndReplace();

    expect(Activity::query()->withTrashed()->pluck('id')->all())->toBe([$latest->id])
        ->and(Grouping::query()->whereIn('activity_id', [$first->id, $second->id])->count())->toBe(0)
        ->and(DB::table(SyncParticipants::table())->whereIn('activity_id', [$first->id, $second->id])->count())->toBe(0)
        ->and(Grouping::query()->where('activity_id', $latest->id)->count())->toBeGreaterThan(0)
        ->and(DB::table(SyncParticipants::table())->where('activity_id', $latest->id)->count())->toBeGreaterThan(0);
});

it('leaves activities with a different verb alone under force too', function () {
    config()->set('storyfeed.replace.delete', 'force');

    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity('create', $delivery)->publish();
    Storyfeed::activity('save', $delivery)->publishAndReplace();

    expect(Activity::query()->withTrashed()->count())->toBe(2);
});

it('refuses an unknown replace.delete value instead of guessing, on the first publish', function () {
    config()->set('storyfeed.replace.delete', 'hard');

    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    // Validated even when nothing is there to supersede yet: a typo that only
    // bit on the SECOND publish would ship, and surface in production.
    expect(fn () => Storyfeed::activity('save', $delivery)->publishAndReplace())
        ->toThrow(InvalidArgumentException::class, 'storyfeed.replace.delete')
        ->and(Activity::query()->withTrashed()->count())->toBe(0);
});
