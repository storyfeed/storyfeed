<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\RebuildSnapshots;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\NullStrategy;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

/**
 * The noise the rebuild-before-trickle trap was missing.
 *
 * `docs/backfilling.md` documents the trap; this check is what makes it loud in
 * the tool an adopter actually runs. The tests that matter are the two edges: it
 * fires on imported rows that WOULD group, and it stays silent on rows that are
 * correctly ungrouped — a check that cries wolf on a healthy install gets turned
 * off, and then the trap is silent again.
 */
beforeEach(function () {
    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $this->order = Customer::create(['name' => 'Order 1001']);
});

function codes(iterable $findings): array
{
    return collect($findings)->pluck('code')->values()->all();
}

function insertUngrouped(User $actor, ?Customer $object, string $uid): void
{
    DB::table('feed_activities')->insert([
        'uid' => $uid,
        'verb' => 'order.note',
        'actor_type' => $actor === null ? null : 'user',
        'actor_id' => $actor?->id,
        'object_type' => $object === null ? null : 'customer',
        'object_id' => $object?->id,
        'published_at' => '2024-03-01 09:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('says nothing about a feed whose activities are all grouped', function () {
    Storyfeed::activity()->actor($this->ines)->verb('order.note', $this->order)->publish();

    expect(codes(Storyfeed::doctor(['grouping'])->all()))->toBe([]);
});

it('names the imported rows the trickle can no longer reach', function () {
    foreach (range(1, 3) as $i) {
        insertUngrouped($this->ines, $this->order, "raw-{$i}");
    }

    // The exact sequence from the guide: rebuild caches them, so the trickle
    // will never look at them again.
    (new RebuildSnapshots)();

    // The check an adopter reaches for is still clean…
    expect(codes(Storyfeed::doctor(['backlog'])->all()))->toBe([]);

    // …and this one is not.
    $findings = collect(Storyfeed::doctor(['grouping'])->all());

    expect(codes($findings))->toBe(['grouping.ungrouped'])
        ->and($findings->first()->subject)->toMatchArray(['ungrouped' => 3, 'sampled' => 3, 'groupable' => 3]);
});

it('names the way out, not only the condition', function () {
    insertUngrouped($this->ines, $this->order, 'raw-1');

    expect(collect(Storyfeed::doctor(['grouping'])->all())->first()->message)
        ->toContain('storyfeed:curate --rehash')
        ->toContain('storyfeed:rebuild');
});

it('goes quiet once the way out has been run', function () {
    foreach (range(1, 3) as $i) {
        insertUngrouped($this->ines, $this->order, "raw-{$i}");
    }

    $this->artisan('storyfeed:curate --rehash')->assertSuccessful();

    expect(codes(Storyfeed::doctor(['grouping'])->all()))->toBe([]);
});

it('stays silent for an app that turned grouping off', function () {
    // NullStrategy groups nothing, so EVERY activity is legitimately ungrouped.
    // A check that counted bare absence would scream at the whole table here,
    // get disabled, and take the real signal with it.
    config()->set('storyfeed.grouping.strategy', NullStrategy::class);

    foreach (range(1, 3) as $i) {
        insertUngrouped($this->ines, $this->order, "raw-{$i}");
    }

    expect(codes(Storyfeed::doctor(['grouping'])->all()))->toBe([]);
});

it('counts a bare verb as groupable, because the repeat axis requires no roles', function () {
    // Guards the assumption the check is written against: under the shipped
    // axes there is no such thing as a correctly-ungrouped activity.
    DB::table('feed_activities')->insert([
        'uid' => 'bare-1',
        'verb' => 'system.heartbeat',
        'published_at' => '2024-03-01 09:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(codes(Storyfeed::doctor(['grouping'])->all()))->toBe(['grouping.ungrouped']);
});

it('reports the whole count and the sample it actually looked at', function () {
    foreach (range(1, 60) as $i) {
        insertUngrouped($this->ines, $this->order, "raw-{$i}");
    }

    $finding = collect(Storyfeed::doctor(['grouping'])->all())->first();

    // The count is the alarm's size; the sample is its evidence. Extrapolating
    // one from the other would be inventing precision.
    expect($finding->subject)->toMatchArray(['ungrouped' => 60, 'sampled' => 50, 'groupable' => 50]);
});
