<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;

it('does nothing when pruning is not configured', function () {
    Storyfeed::activity()->verb('ping')->publishedAt(now()->subYears(2))->publish();

    $this->artisan('storyfeed:prune')
        ->expectsOutputToContain('Pruning is disabled')
        ->assertSuccessful();

    expect(Activity::query()->withTrashed()->count())->toBe(1);
});

it('force-deletes expired activities and their grouping rows', function () {
    config()->set('storyfeed.prune.after_days', 90);

    $old = Storyfeed::activity()->verb('ping')->publishedAt(now()->subDays(120))->publish();
    $fresh = Storyfeed::activity()->verb('ping')->publish();

    $this->artisan('storyfeed:prune')->assertSuccessful();

    expect(Activity::query()->withTrashed()->pluck('id')->all())->toBe([$fresh->id])
        ->and(Grouping::query()->where('activity_id', $old->id)->count())->toBe(0)
        ->and(Grouping::query()->where('activity_id', $fresh->id)->count())->toBeGreaterThan(0);
});

it('purges expired soft-deleted rows too', function () {
    config()->set('storyfeed.prune.after_days', 30);

    $activity = Storyfeed::activity()->verb('ping')->publishedAt(now()->subDays(60))->publish();
    $activity->delete();

    $this->artisan('storyfeed:prune')->assertSuccessful();

    expect(Activity::query()->withTrashed()->count())->toBe(0);
});

it('honors a --days override', function () {
    Storyfeed::activity()->verb('ping')->publishedAt(now()->subDays(10))->publish();

    $this->artisan('storyfeed:prune', ['--days' => 5])->assertSuccessful();

    expect(Activity::query()->withTrashed()->count())->toBe(0);
});
