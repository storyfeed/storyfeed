<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\TrickleSnapshots;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Meta;
use Storyfeed\Support\MaintenanceHistory;
use Storyfeed\Support\SyncToken;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

beforeEach(function () {
    Delivery::$extendedFeedShape = false;
});

afterEach(function () {
    Delivery::$extendedFeedShape = false;
});

it('reports absent evidence without inventing a run or mutating storage', function () {
    expect(MaintenanceHistory::recent('curate'))->toBe([]);
    $report = Storyfeed::doctor(['maintenance']);

    expect($report->withCode('maintenance.empty'))->toHaveCount(2)
        ->and(Meta::query()->count())->toBe(0);
});

it('keeps a curation repair spike followed by quiet passes including resettled peers outside the command window', function () {
    $target = Customer::create(['name' => 'Project']);
    foreach (['Ann', 'Bob', 'Cid'] as $index => $name) {
        Storyfeed::activity('upload')->actor($name)
            ->object(Delivery::create(['tracking_number' => $name]))->target($target)
            ->publishedAt($index === 2 ? now() : now()->subHour())->publish();
    }
    Grouping::query()->where('bucket', '!=', 'batch')->update(['winner' => false]);
    Grouping::query()->where('bucket', 'repeat')->update(['winner' => true]);

    $at = now()->toIso8601String();
    $this->artisan('storyfeed:curate --window=0')->assertSuccessful();
    $this->travel(1)->minutes();
    $this->artisan('storyfeed:curate')->assertSuccessful();

    expect(MaintenanceHistory::recent('curate'))->toBe([
        ['at' => $at, 'processed' => 1, 'restamped' => 3, 'rehashed' => 0],
        ['at' => now()->toIso8601String(), 'processed' => 3, 'restamped' => 0, 'rehashed' => 0],
    ]);

    $before = Meta::query()->get()->toArray();
    $report = Storyfeed::doctor(['maintenance']);
    expect($report->withCode('maintenance.curate')->pluck('subject')->all())->toBe(MaintenanceHistory::recent('curate'))
        ->and(Meta::query()->get()->toArray())->toBe($before);
});

it('counts changed hashes separately from winner changes during rehash', function () {
    $activity = Storyfeed::activity('ping')->actor('Importer')->publish();
    Grouping::query()->where('activity_id', $activity->id)->where('bucket', 'repeat')->update(['hash' => 'stale']);

    $this->artisan('storyfeed:curate --rehash')->assertSuccessful();
    $this->artisan('storyfeed:curate --rehash')->assertSuccessful();

    $runs = MaintenanceHistory::recent('curate');
    expect($runs[0])->toMatchArray(['processed' => 1, 'restamped' => 0, 'rehashed' => 1])
        ->and($runs[1])->toMatchArray(['processed' => 1, 'restamped' => 0, 'rehashed' => 0]);
});

it('persists direct trickle results once per pass and retains a shape spike followed by quiet passes', function () {
    foreach (range(1, 3) as $i) {
        Storyfeed::activity('confirm', Delivery::create(['tracking_number' => "TN-{$i}"]))->publish();
    }
    Delivery::$extendedFeedShape = true;
    $first = (new TrickleSnapshots)();
    $second = (new TrickleSnapshots)();
    $this->artisan('storyfeed:trickle')->assertSuccessful();

    $runs = MaintenanceHistory::recent('trickle');
    expect($runs)->toHaveCount(3)
        ->and($runs[0])->toMatchArray($first)
        ->and($runs[1])->toMatchArray($second)
        ->and(array_column($runs, 'reshaped'))->toBe([3, 0, 0])
        ->and(Storyfeed::doctor(['maintenance'])->withCode('maintenance.trickle'))->toHaveCount(3);
});

it('preserves both unresolved and pruned counts rather than merging away their meaning', function () {
    Activity::query()->create(['verb' => 'confirm', 'object_type' => 'delivery', 'object_id' => 999, 'published_at' => now()]);
    $reported = (new TrickleSnapshots)(prune: false);
    $pruned = (new TrickleSnapshots)(prune: true);

    expect(MaintenanceHistory::recent('trickle')[0])->toMatchArray($reported)
        ->and($reported['unresolved'])->toBe(1)
        ->and($reported['pruned'])->toBe(0)
        ->and(MaintenanceHistory::recent('trickle')[1])->toMatchArray($pruned)
        ->and($pruned['pruned'])->toBe(1);
});

it('bounds history per command even at one timestamp without touching unrelated metadata', function () {
    $token = SyncToken::bump();
    MaintenanceHistory::record('trickle', ['snapshotted' => 0, 'pruned' => 0, 'unresolved' => 0, 'reshaped' => 0]);
    foreach (range(1, 23) as $count) {
        MaintenanceHistory::record('curate', ['processed' => $count, 'restamped' => 0, 'rehashed' => 0]);
    }

    expect(array_column(MaintenanceHistory::recent('curate'), 'processed'))->toBe(range(4, 23))
        ->and(MaintenanceHistory::recent('trickle'))->toHaveCount(1)
        ->and(Meta::query()->count())->toBe(22)
        ->and(SyncToken::current())->toBe($token)
        ->and(Meta::query()->get()->every(fn ($row) => strlen($row->value) <= 255))->toBeTrue();
});

it('uses the configured meta table and records empty successful commands', function () {
    Schema::rename('feed_meta', 'custom_feed_meta');
    config()->set('storyfeed.tables.meta', 'custom_feed_meta');
    $this->artisan('storyfeed:curate')->assertSuccessful();

    expect(MaintenanceHistory::recent('curate'))->toBe([
        ['at' => now()->toIso8601String(), 'processed' => 0, 'restamped' => 0, 'rehashed' => 0],
    ]);
});

it('does not manufacture a completed report when a pass fails', function () {
    Storyfeed::activity('ping')->actor('Importer')->publish();
    Schema::drop('feed_groupings');

    $this->withoutExceptionHandling();
    expect(fn () => $this->artisan('storyfeed:curate')->run())->toThrow(QueryException::class)
        ->and(MaintenanceHistory::recent('curate'))->toBe([]);
});
