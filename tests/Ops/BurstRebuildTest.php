<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\RebuildGroupingBursts;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Axis;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Tests\Fixtures\LegacyBurstRebuild;
use Storyfeed\Tests\Fixtures\NewsroomHistory;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

function burstRebuildSnapshot(): array
{
    return [
        Grouping::query()->orderBy('activity_id')->orderBy('bucket')->get(['activity_id', 'bucket', 'hash', 'winner'])->toArray(),
        DB::table('feed_grouping_bursts')->orderBy('key')->get(['key', 'hash', 'opened_at', 'last_activity_at', 'within_seconds', 'ceiling_seconds'])->map(fn ($row) => (array) $row)->all(),
    ];
}

it('matches the original chronological replay byte for byte across chunk boundaries', function () {
    NewsroomHistory::seed(180);
    $person = User::first();
    $doc = Delivery::first();
    foreach ([0, 0, 1, 899, 900, 1799, 14400, 14401] as $i => $second) {
        Storyfeed::activity()->actor(User::find($i % 3 + 1))->verb('comment', $doc)
            ->publishedAt(now()->subDay()->addSeconds($second)->addMicroseconds($i % 2))->publish();
    }
    Storyfeed::activity()->actor($person)->verb('upload')->objects(Delivery::query()->limit(2)->get())->publish();
    Activity::query()->whereIn('id', [1, 4, 12, 181, 184])->update(['deleted_at' => now()]);
    Grouping::query()->insert(['activity_id' => 1, 'bucket' => 'summary.day', 'hash' => 'legacy']);
    DB::beginTransaction();
    (new LegacyBurstRebuild)();
    $oracle = burstRebuildSnapshot();
    DB::rollBack();
    (new RebuildGroupingBursts)(batchSize: 7);
    expect(serialize(burstRebuildSnapshot()))->toBe(serialize($oracle));
});

it('resumes from the last committed replay chunk and finishes with identical results', function () {
    NewsroomHistory::seed(60);
    DB::beginTransaction();
    (new LegacyBurstRebuild)();
    $oracle = burstRebuildSnapshot();
    DB::rollBack();
    expect(fn () => (new RebuildGroupingBursts)(batchSize: 7, progress: function ($done, $total, $phase) {
        if ($phase === 'replay' && $done >= 21) {
            throw new RuntimeException('simulated termination after commit');
        }
    }))->toThrow(RuntimeException::class, 'simulated termination');
    expect(fn () => (new RebuildGroupingBursts)())->toThrow(RuntimeException::class, '--resume');
    $stats = (new RebuildGroupingBursts)(resume: true, batchSize: 7);
    expect($stats['processed'])->toBe(60)
        ->and(serialize(burstRebuildSnapshot()))->toBe(serialize($oracle));
});

it('refuses a rebuild until the operator confirms publishers are paused', function () {
    $this->artisan('storyfeed:curate --rebuild-bursts')->assertFailed();
    $this->artisan('storyfeed:curate --rebuild-bursts --writers-paused')->assertSuccessful();
    $this->artisan('storyfeed:curate --resume')->assertFailed();
    $this->artisan('storyfeed:curate --rebuild-bursts --writers-paused --resume')->assertFailed();
});

it('preserves custom nonburst axes and deleted prefix winners', function () {
    Storyfeed::axes([Axis::make('studio')->key('v:ta:tid')->eligibleWhenDistinct('actor', 3)], before: 'actors');
    NewsroomHistory::seed(30);
    Activity::query()->whereIn('id', [1, 7, 18])->update(['deleted_at' => now()]);
    DB::beginTransaction();
    (new LegacyBurstRebuild)();
    $oracle = burstRebuildSnapshot();
    DB::rollBack();
    (new RebuildGroupingBursts)(batchSize: 7);
    expect(serialize(burstRebuildSnapshot()))->toBe(serialize($oracle));
});

it('preserves null event dates and native engine ordering on resume', function () {
    NewsroomHistory::seed(30);
    Activity::query()->whereIn('id', [1, 2, 20])->update(['published_at' => null]);
    DB::beginTransaction();
    (new LegacyBurstRebuild)();
    $oracle = burstRebuildSnapshot();
    DB::rollBack();
    expect(fn () => (new RebuildGroupingBursts)(batchSize: 2, progress: function ($done, $total, $phase) {
        if ($phase === 'replay' && $done >= 2) {
            throw new RuntimeException('stop');
        }
    }))->toThrow(RuntimeException::class, 'stop');
    (new RebuildGroupingBursts)(resume: true, batchSize: 7);
    expect(serialize(burstRebuildSnapshot()))->toBe(serialize($oracle));
});

it('rejects resume with changed policy or newly published history', function () {
    NewsroomHistory::seed(30);
    expect(fn () => (new RebuildGroupingBursts)(batchSize: 7, progress: function () {
        throw new RuntimeException('stop');
    }))->toThrow(RuntimeException::class, 'stop');
    config()->set('storyfeed.grouping.bursts.within', '2 minutes');
    expect(fn () => (new RebuildGroupingBursts)(resume: true))->toThrow(RuntimeException::class, 'policy');
    config()->set('storyfeed.grouping.bursts.within', '15 minutes');
    Storyfeed::activity()->actor(User::first())->verb('comment', Delivery::first())->publish();
    expect(fn () => (new RebuildGroupingBursts)(resume: true))->toThrow(RuntimeException::class, 'history');
});

it('prevents a second rebuild from taking over an active run', function () {
    NewsroomHistory::seed(30);
    $checked = false;
    (new RebuildGroupingBursts)(batchSize: 7, progress: function () use (&$checked) {
        if (! $checked) {
            expect(fn () => (new RebuildGroupingBursts)(resume: true))->toThrow(RuntimeException::class, 'running');
            $checked = true;
        }
    });
    expect($checked)->toBeTrue();
});

it('resumes interrupted bulk winner stamping', function () {
    NewsroomHistory::seed(60);
    DB::beginTransaction();
    (new LegacyBurstRebuild)();
    $oracle = burstRebuildSnapshot();
    DB::rollBack();
    expect(fn () => (new RebuildGroupingBursts)(batchSize: 7, progress: function ($done, $total, $phase) {
        if ($phase === 'curate' && $done >= 7) {
            throw new RuntimeException('curation interrupted');
        }
    }))->toThrow(RuntimeException::class, 'curation interrupted');
    (new RebuildGroupingBursts)(resume: true, batchSize: 7);
    expect(serialize(burstRebuildSnapshot()))->toBe(serialize($oracle));
});

it('matches the oracle with dense social thresholds, mixed types and tombstones', function () {
    config()->set('storyfeed.grouping.bursts', ['within' => '10 seconds', 'ceiling' => '40 seconds']);
    $rows = [];
    foreach (range(1, 240) as $id) {
        $at = now()->subDay()->addSeconds(($id * 37) % 120)->addMicroseconds($id % 3)->format('Y-m-d H:i:s.u');
        $rows[] = ['id' => $id, 'uid' => str_pad((string) $id, 26, '0', STR_PAD_LEFT),
            'verb' => $id % 7 === 0 ? 'upload' : 'comment',
            'actor_type' => $id % 17 === 0 ? null : 'user', 'actor_id' => $id % 17 === 0 ? null : (string) ($id % 5),
            'object_type' => $id % 13 === 0 ? null : ($id % 2 ? 'customer' : 'delivery'), 'object_id' => $id % 13 === 0 ? null : (string) ($id % 4),
            'target_type' => $id % 11 === 0 ? null : 'customer', 'target_id' => $id % 11 === 0 ? null : (string) ($id % 3),
            'published_at' => $at, 'deleted_at' => $id % 9 === 0 ? $at : null];
    }
    DB::table('feed_activities')->insert($rows);
    Grouping::query()->insert([
        ['activity_id' => 1, 'bucket' => null, 'hash' => 'legacy-null', 'winner' => null],
        ['activity_id' => 1, 'bucket' => 'batch', 'hash' => 'window', 'winner' => null],
        ['activity_id' => 2, 'bucket' => 'composite', 'hash' => 'claimed-member', 'winner' => true],
    ]);
    DB::beginTransaction();
    (new LegacyBurstRebuild)();
    $oracle = burstRebuildSnapshot();
    DB::rollBack();
    (new RebuildGroupingBursts)(batchSize: 11);
    expect(serialize(burstRebuildSnapshot()))->toBe(serialize($oracle));
});

it('rolls back membership writes and the cursor together when a chunk fails', function () {
    NewsroomHistory::seed(30);
    DB::beginTransaction();
    (new LegacyBurstRebuild)();
    $oracle = burstRebuildSnapshot();
    DB::rollBack();
    $fail = true;
    DB::listen(function ($query) use (&$fail) {
        if ($fail && str_starts_with($query->sql, 'insert into') && str_contains($query->sql, 'feed_groupings')) {
            $fail = false;
            throw new RuntimeException('injected membership failure');
        }
    });
    expect(fn () => (new RebuildGroupingBursts)(batchSize: 7))->toThrow(RuntimeException::class, 'injected membership failure');
    expect(Grouping::query()->where('bucket', 'repeat')->count())->toBe(0);
    (new RebuildGroupingBursts)(resume: true, batchSize: 7);
    expect(serialize(burstRebuildSnapshot()))->toBe(serialize($oracle));
});

it('restarts interrupted replay explicitly after a policy change', function () {
    NewsroomHistory::seed(30);
    expect(fn () => (new RebuildGroupingBursts)(batchSize: 7, progress: function () {
        throw new RuntimeException('stop');
    }))->toThrow(RuntimeException::class, 'stop');
    config()->set('storyfeed.grouping.bursts.within', '2 seconds');
    (new RebuildGroupingBursts)(restart: true, batchSize: 7);
    $snapshot = burstRebuildSnapshot();
    (new RebuildGroupingBursts)(batchSize: 11);
    expect(serialize(burstRebuildSnapshot()))->toBe(serialize($snapshot));
});

it('keeps default replay database round trips bounded by batches', function () {
    NewsroomHistory::seed(1000);
    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });
    (new RebuildGroupingBursts)();
    // Original rebuild used about 41,000 statements for these 1,000 rows.
    expect($queries)->toBeLessThan(300);
});
