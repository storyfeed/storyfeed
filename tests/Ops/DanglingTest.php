<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

/*
 * The `dangling` check (todo 662): grouping and participant rows whose
 * activity no longer exists, trashed included. Counted and never removed —
 * deleting them is a decision the package has not made.
 */

/** Two grouped, indexed activities on one delivery; returns their ids. */
function publishPair(): array
{
    $customer = Customer::create(['name' => 'Acme Co.']);
    $delivery = Delivery::create(['customer_id' => $customer->id, 'tracking_number' => 'TN-1']);

    $one = Storyfeed::activity('confirm', $delivery)->for($customer)->publish();
    $two = Storyfeed::activity('dispatch', $delivery)->for($customer)->publish();

    return [$one->id, $two->id];
}

/** The bug's shape: the activities go, their rows stay. */
function hardDeleteBypassingBookkeeping(array $ids): void
{
    DB::table('feed_activities')->whereIn('id', $ids)->delete();
}

it('says nothing on a healthy install', function () {
    publishPair();
    Storyfeed::activity()->verb('ping')->publish();

    $report = Storyfeed::doctor(['dangling']);

    expect($report->all())->toBeEmpty()
        ->and($report->isHealthy())->toBeTrue();
});

it('does not count rows whose activity is merely trashed', function () {
    [$one] = publishPair();

    Activity::query()->findOrFail($one)->delete();

    expect(Activity::query()->withTrashed()->find($one)->trashed())->toBeTrue()
        ->and(Grouping::query()->where('activity_id', $one)->count())->toBeGreaterThan(0)
        ->and(Storyfeed::doctor(['dangling'])->all())->toBeEmpty();
});

it('counts grouping and participant rows whose activity is gone, as reportage rather than a finding', function () {
    $ids = publishPair();
    $kept = Storyfeed::activity()->verb('ping')->publish();

    $groupings = Grouping::query()->whereIn('activity_id', $ids)->count();
    $participants = DB::table(SyncParticipants::table())->whereIn('activity_id', $ids)->count();

    expect($groupings)->toBeGreaterThan(0)
        ->and($participants)->toBeGreaterThan(0);

    hardDeleteBypassingBookkeeping($ids);

    $report = Storyfeed::doctor(['dangling']);

    $grouping = $report->withCode('dangling.groupings')->sole();
    $participant = $report->withCode('dangling.participants')->sole();

    expect($grouping->severity)->toBe(Severity::Info)
        ->and($grouping->subject)->toBe(['table' => 'feed_groupings', 'dangling' => $groupings])
        ->and($grouping->message)->toContain("{$groupings} grouping rows")
        ->and($grouping->message)->toContain('storyfeed:prune')
        ->and($grouping->fix)->toBeNull()
        ->and($participant->severity)->toBe(Severity::Info)
        ->and($participant->subject)->toBe(['table' => 'feed_participants', 'dangling' => $participants])
        ->and($participant->fix)->toBeNull()
        ->and($report->isHealthy())->toBeTrue()
        ->and($report->count())->toBe(0);

    // The kept activity's rows are not in the count.
    expect(Grouping::query()->where('activity_id', $kept->id)->count())->toBeGreaterThan(0);
});

it('never offers to remove them, and removes nothing itself', function () {
    $ids = publishPair();
    hardDeleteBypassingBookkeeping($ids);

    $before = [
        Grouping::query()->count(),
        DB::table(SyncParticipants::table())->count(),
    ];

    $report = Storyfeed::doctor(['dangling']);

    expect($report->all())->toHaveCount(2)
        ->and($report->fixes())->toBeEmpty()
        ->and(collect($report->all())->pluck('message')->implode(' '))->not->toContain('remove', 'Run `', '--prune')
        ->and([Grouping::query()->count(), DB::table(SyncParticipants::table())->count()])->toBe($before);
});

it('stays silent rather than throwing when a dependent table is missing', function () {
    $ids = publishPair();
    hardDeleteBypassingBookkeeping($ids);

    Schema::drop('feed_groupings');

    $codes = collect(Storyfeed::doctor(['dangling'])->all())->pluck('code')->all();

    expect($codes)->toBe(['dangling.participants']);

    Schema::drop('feed_participants');

    expect(Storyfeed::doctor(['dangling'])->all())->toBeEmpty();
});

it('stays silent rather than throwing when the activities table is missing', function () {
    Schema::drop('feed_activities');

    expect(Storyfeed::doctor(['dangling'])->all())->toBeEmpty();
});
