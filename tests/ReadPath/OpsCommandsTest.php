<?php

use Storyfeed\Models\Activity;
use Storyfeed\Models\Snapshot;
use Workbench\App\Models\Delivery;

it('rebuilds snapshots and backfills cached links for raw activities', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Snapshot::query()->delete(); // wipe what the fixture trait wrote on save

    $activity = Activity::query()->create([
        'verb' => 'confirm',
        'object_type' => 'delivery',
        'object_id' => $delivery->id,
        'published_at' => now(),
    ]);

    $this->artisan('storyfeed:rebuild')->assertSuccessful();

    expect($activity->refresh()->cached_object_id)->not->toBeNull()
        ->and(Snapshot::query()->where('model_type', 'delivery')->value('label'))->toBe('Delivery #TN-1');
});

it('trickles uncached activities newest-first', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Snapshot::query()->delete();

    $activity = Activity::query()->create([
        'verb' => 'confirm',
        'object_type' => 'delivery',
        'object_id' => $delivery->id,
        'published_at' => now(),
    ]);

    $this->artisan('storyfeed:trickle')->assertSuccessful();

    expect($activity->refresh()->cached_object_id)->not->toBeNull();
});

it('keeps an orphaned activity and says so, rather than deleting it on a schedule', function () {
    $activity = Activity::query()->create([
        'verb' => 'confirm',
        'object_type' => 'delivery',
        'object_id' => 12345, // no such delivery
        'published_at' => now(),
    ]);

    // The output has to name the LIKELY CAUSE, not just a count. An app that
    // reads "1 orphan" as junk reaches for --prune, which is the one response
    // that removes the evidence of the bug instead of fixing it.
    $this->artisan('storyfeed:trickle')
        ->expectsOutputToContain('cannot be resolved')
        ->expectsOutputToContain('missing Feedable')
        ->assertSuccessful();

    expect(Activity::query()->find($activity->id))->not->toBeNull();
});

it('prunes it only when the flag is passed, and soft-deletes when it does', function () {
    $activity = Activity::query()->create([
        'verb' => 'confirm',
        'object_type' => 'delivery',
        'object_id' => 12345,
        'published_at' => now(),
    ]);

    $this->artisan('storyfeed:trickle --prune')->assertSuccessful();

    expect(Activity::query()->find($activity->id))->toBeNull()
        ->and(Activity::query()->withTrashed()->find($activity->id))->not->toBeNull();
});
