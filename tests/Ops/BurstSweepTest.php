<?php

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\SweepGroupingBursts;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Support\Chronology;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Closed burst windows are swept after storyfeed.grouping.burst_retention_days
 * (#91). Reads never touch the table, so a sweep changes no feed; the one
 * behaviour change is a late arrival past the grace opening its own group.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-09 12:00:00');
    config()->set('storyfeed.grouping.batch.enabled', false);
    $this->sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
});

afterEach(function () {
    Carbon::setTestNow();
});

/** Publish as it happened: at that moment, so the row's lock stamp is that moment too. */
function sweptRecordedAt(Carbon $at, Delivery $object, ?Carbon $publishedAt = null): Activity
{
    Carbon::setTestNow($at);

    try {
        return Storyfeed::activity()->actor(test()->sally)->verb('revise', $object)
            ->when($publishedAt !== null, fn ($pending) => $pending->publishedAt($publishedAt))
            ->publish();
    } finally {
        Carbon::setTestNow('2026-10-09 12:00:00');
    }
}

function sweptRepeatHash(Activity $activity): string
{
    return Grouping::query()->where('activity_id', $activity->id)->where('bucket', 'repeat')->value('hash');
}

function sweptWindows(): int
{
    return DB::table('feed_grouping_bursts')->count();
}

function sweptRow(string $key, string $opened, string $last, int $within = 900, int $ceiling = 14400, ?string $locked = null): void
{
    DB::table('feed_grouping_bursts')->insert([
        'key' => str_pad($key, 64, '0'), 'hash' => 'b1:'.str_pad($key, 64, '0').':x',
        'opened_at' => Chronology::stamp($opened), 'last_activity_at' => Chronology::stamp($last),
        'within_seconds' => $within, 'ceiling_seconds' => $ceiling, 'locked_at' => $locked ?? $last,
    ]);
}

it('sweeps a window closed past the grace and keeps one inside it or still open', function () {
    $old = sweptRecordedAt(now()->subDays(10), Delivery::create(['tracking_number' => 'old.pdf']));
    $recent = sweptRecordedAt(now()->subDays(3), Delivery::create(['tracking_number' => 'recent.pdf']));
    $open = sweptRecordedAt(now()->subMinutes(5), Delivery::create(['tracking_number' => 'open.pdf']));
    $windows = sweptWindows();

    $swept = (new SweepGroupingBursts)();

    expect($swept)->toBeGreaterThan(0)
        ->and(sweptWindows())->toBe($windows - $swept)
        ->and(DB::table('feed_grouping_bursts')->whereRaw('substr(hash, 69) = ?', [$old->uid])->count())->toBe(0)
        ->and(DB::table('feed_grouping_bursts')->whereRaw('substr(hash, 69) = ?', [$recent->uid])->count())->toBeGreaterThan(0)
        ->and(DB::table('feed_grouping_bursts')->whereRaw('substr(hash, 69) = ?', [$open->uid])->count())->toBeGreaterThan(0);
});

it('judges each window by its own stored policy', function () {
    $tenDaysAgo = now()->subDays(10)->format('Y-m-d H:i:s');
    sweptRow('a', $tenDaysAgo, $tenDaysAgo);
    // A verb with a month-long quiet gap and ceiling: still open.
    sweptRow('b', $tenDaysAgo, $tenDaysAgo, within: 30 * 86400, ceiling: 30 * 86400);
    // Quiet gap long, ceiling short: closed by the ceiling, ten days ago.
    sweptRow('c', $tenDaysAgo, $tenDaysAgo, within: 30 * 86400, ceiling: 3600);
    // Closed six days ago: inside the grace.
    sweptRow('d', now()->subDays(6)->format('Y-m-d H:i:s'), now()->subDays(6)->format('Y-m-d H:i:s'));

    expect((new SweepGroupingBursts)())->toBe(2)
        ->and(DB::table('feed_grouping_bursts')->orderBy('key')->pluck('key')->map(fn ($key) => $key[0])->all())->toBe(['b', 'd']);
});

it('never sweeps a window a publisher touched inside the grace', function () {
    $tenDaysAgo = now()->subDays(10)->format('Y-m-d H:i:s');
    // A late arrival before the window opened locks the row without moving it.
    sweptRow('a', $tenDaysAgo, $tenDaysAgo, locked: now()->format('Y-m-d H:i:s'));

    expect((new SweepGroupingBursts)())->toBe(0)->and(sweptWindows())->toBe(1);
});

it('sweeps in chunks', function () {
    $old = Chronology::stamp(now()->subDays(30));
    $rows = SweepGroupingBursts::CHUNK * 2 + 5;

    foreach (collect(range(1, $rows))->chunk(200) as $chunk) {
        DB::table('feed_grouping_bursts')->insert($chunk->map(fn ($i) => [
            'key' => hash('sha256', (string) $i), 'hash' => 'b1:x', 'opened_at' => $old, 'last_activity_at' => $old,
            'within_seconds' => 900, 'ceiling_seconds' => 14400, 'locked_at' => now()->subDays(30),
        ])->values()->all());
    }

    expect((new SweepGroupingBursts)())->toBe($rows)->and(sweptWindows())->toBe(0);
});

it('lets a late activity inside the grace join its burst', function () {
    $doc = Delivery::create(['tracking_number' => 'tokens.pdf']);
    $first = sweptRecordedAt(now()->subDays(3), $doc);
    (new SweepGroupingBursts)();

    $late = sweptRecordedAt(now(), $doc, publishedAt: now()->subDays(3)->addMinutes(5));

    expect(sweptRepeatHash($late))->toBe(sweptRepeatHash($first));
});

it('opens a new group for a late activity past the grace', function () {
    $doc = Delivery::create(['tracking_number' => 'tokens.pdf']);
    $first = sweptRecordedAt(now()->subDays(10), $doc);
    (new SweepGroupingBursts)();

    $late = sweptRecordedAt(now(), $doc, publishedAt: now()->subDays(10)->addMinutes(5));

    expect(sweptRepeatHash($late))->not->toBe(sweptRepeatHash($first));
});

it('leaves every read unchanged', function () {
    foreach ([12, 9, 8, 2, 0] as $days) {
        foreach ([0, 4, 9] as $minutes) {
            sweptRecordedAt(now()->subDays($days)->addMinutes($minutes), Delivery::create(['tracking_number' => "d{$days}.pdf"]));
            sweptRecordedAt(now()->subDays($days)->addMinutes($minutes + 1), Delivery::create(['tracking_number' => 'shared.pdf']));
        }
    }
    $read = fn () => [
        Storyfeed::feed()->live()->get()->toArray(),
        Storyfeed::feed()->log()->get()->toArray(),
    ];
    $before = $read();

    expect((new SweepGroupingBursts)())->toBeGreaterThan(0)
        ->and($read())->toBe($before);
});

it('runs with storyfeed:prune, even when activities are never pruned', function () {
    sweptRecordedAt(now()->subDays(10), Delivery::create(['tracking_number' => 'old.pdf']));
    $windows = sweptWindows();

    $this->artisan('storyfeed:prune', ['--pretend' => true])
        ->expectsOutputToContain("Would sweep {$windows} closed burst windows.")
        ->assertSuccessful();
    expect(sweptWindows())->toBe($windows);

    $this->artisan('storyfeed:prune', ['--bursts' => true])
        ->doesntExpectOutputToContain('Pruning is disabled')
        ->expectsOutputToContain("Swept {$windows} closed burst windows.")
        ->assertSuccessful();
    expect(sweptWindows())->toBe(0)->and(Activity::query()->count())->toBe(1);
});

it('keeps every window when the retention is null', function () {
    config()->set('storyfeed.grouping.burst_retention_days', null);
    sweptRecordedAt(now()->subDays(100), Delivery::create(['tracking_number' => 'old.pdf']));

    expect((new SweepGroupingBursts)())->toBe(0)->and(sweptWindows())->toBeGreaterThan(0);
    $this->artisan('storyfeed:prune', ['--bursts' => true])->doesntExpectOutputToContain('burst windows')->assertSuccessful();
});

it('refuses a grace shorter than a day', function (mixed $days) {
    config()->set('storyfeed.grouping.burst_retention_days', $days);

    (new SweepGroupingBursts)();
})->with([0, -1, 'week'])->throws(InvalidArgumentException::class, 'burst_retention_days');

it('is scheduled daily', function () {
    $event = collect(app(Schedule::class)->events())
        ->firstWhere('command', Application::formatCommandString('storyfeed:prune').' --bursts');

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 0 * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
