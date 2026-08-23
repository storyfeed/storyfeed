<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Workbench\App\Models\Delivery;

it('seeds a demo tenant', function () {
    $this->artisan('storyfeed:demo', ['--days' => 2])->assertSuccessful();

    expect(Activity::count())->toBeGreaterThan(0)
        ->and(Party::where('key', 'like', 'demo-%')->count())->toBeGreaterThan(0);
});

it('names the cast in its output, so nobody mistakes it for real data', function () {
    $this->artisan('storyfeed:demo', ['--days' => 1])
        ->expectsOutputToContain('The cast is invented and lives only in the feed')
        ->expectsOutputToContain('Priya Raman')
        ->assertSuccessful();
});

it('reseeds without stacking duplicates when run again with --fresh', function () {
    $this->artisan('storyfeed:demo', ['--days' => 2, '--seed' => 3])->assertSuccessful();
    $first = Activity::count();

    $this->artisan('storyfeed:demo', ['--days' => 2, '--seed' => 3, '--fresh' => true])->assertSuccessful();

    // Same seed, same days, cleared first — the feed is reproduced, not doubled.
    expect(Activity::count())->toBe($first);
});

it('clears without seeding', function () {
    $this->artisan('storyfeed:demo', ['--days' => 1])->assertSuccessful();

    $this->artisan('storyfeed:demo', ['--clear' => true])->assertSuccessful();

    expect(Activity::count())->toBe(0)
        ->and(Party::where('key', 'like', 'demo-%')->count())->toBe(0);
});

it('leaves the application\'s own activities alone when clearing', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-REAL']);
    Storyfeed::activity('confirm', $delivery)->publish();

    $this->artisan('storyfeed:demo', ['--days' => 1])->assertSuccessful();
    $this->artisan('storyfeed:demo', ['--clear' => true])->assertSuccessful();

    expect(Activity::count())->toBe(1)
        ->and(Activity::first()->verb)->toBe('confirm');
});

it('refuses to run in production unless the operator confirms', function () {
    // The hazard runs both ways: fake rows landing in a real feed, and a demo
    // command pointed at real people's data. Laravel's own production
    // confirmation is used rather than a bespoke check, so the behaviour is the
    // one every Laravel developer already expects from migrate:fresh.
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('storyfeed:demo', ['--days' => 1])
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertFailed();

    expect(Activity::count())->toBe(0);
});

it('runs in production when explicitly forced', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('storyfeed:demo', ['--days' => 1, '--force' => true])->assertSuccessful();

    expect(Activity::count())->toBeGreaterThan(0);
});
