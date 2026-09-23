<?php

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Storyfeed\Actions\CurateCluster;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Actions\WriteGroupings;
use Storyfeed\Models\Activity;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * A popular entity: 5,000 activities naming one delivery, by ten actors,
 * three verbs, spread over three weeks, so the repoint meets realistic
 * clusters and winners.
 *
 * The assertions are QUERY BUDGETS, not timings: a count is the same on
 * every CI cell, and it is what grows when a per-row query creeps back into
 * the loop. Set STORYFEED_PERF=1 to print the timings and per-chunk counts.
 */
it('tombstones and restores an entity with 5,000 activities in bounded queries', function () {
    $users = collect(range(1, 10))->map(fn ($i) => User::create(['name' => "User {$i}", 'email' => "u{$i}@example.com"]));
    $delivery = Delivery::create(['tracking_number' => 'POPULAR']);

    // Seeded in bulk rather than published one by one, which would spend
    // twenty seconds per CI cell before the test had begun.
    $snapshots = $users->mapWithKeys(fn (User $user) => [$user->id => (new SnapshotEntity)($user)->getKey()]);
    $object = (new SnapshotEntity)($delivery)->getKey();
    $now = now();

    foreach (array_chunk(range(1, 5000), 500) as $chunk) {
        DB::table('feed_activities')->insert(array_map(fn (int $i) => [
            'uid' => (string) Str::ulid(),
            'verb' => ['confirm', 'dispatch', 'note'][$i % 3],
            'actor_type' => 'user',
            'actor_id' => $users[$i % 10]->id,
            'cached_actor_id' => $snapshots[$users[$i % 10]->id],
            'object_type' => 'delivery',
            'object_id' => $delivery->id,
            'cached_object_id' => $object,
            'published_at' => $now->copy()->subHours($i % 500)->format('Y-m-d H:i:s.u'),
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }

    Activity::query()->chunkById(500, function ($activities) {
        foreach ($activities as $activity) {
            (new SyncParticipants)($activity);
        }

        (new WriteGroupings)->many($activities);
    });

    (new CurateCluster)->repairMany(Grouping::query()->distinct()->get(['bucket', 'hash'])
        ->map(fn (Grouping $row) => [(string) $row->bucket, (string) $row->hash]));

    $winners = Grouping::query()->where('winner', true)->count();

    $queries = 0;
    $chunks = [];
    DB::listen(function () use (&$queries, &$chunks) {
        $queries++;

        if ($chunks !== []) {
            $chunks[array_key_last($chunks)]++;
        }
    });
    // A chunk's transaction is the outermost one; nested ones are savepoints.
    Event::listen(TransactionBeginning::class, function ($event) use (&$chunks) {
        if ($event->connection->transactionLevel() === 1) {
            $chunks[] = 0;
        }
    });

    $start = microtime(true);
    $delivery->delete();
    $deleted = [microtime(true) - $start, $queries, $chunks];

    expect(Activity::query()->where('object_type', 'storyfeed.tombstone')->count())->toBe(5000)
        ->and(Grouping::query()->where('winner', true)->count())->toBe($winners);

    $queries = 0;
    $chunks = [];
    $start = microtime(true);
    $delivery->restore();
    $restored = [microtime(true) - $start, $queries, $chunks];

    expect(Activity::query()->where('object_type', 'delivery')->count())->toBe(5000)
        ->and(Grouping::query()->where('winner', true)->count())->toBe($winners)
        ->and(FeedTombstone::query()->count())->toBe(0);

    if (env('STORYFEED_PERF')) {
        foreach (['delete' => $deleted, 'restore' => $restored] as $name => [$seconds, $count, $perTransaction]) {
            fwrite(STDERR, sprintf("\n%s: %.2fs, %d queries; per outer transaction: %s", $name, $seconds, $count, implode(', ', $perTransaction)));
        }

        fwrite(STDERR, "\n");
    }

    // 12 queries per chunk of 500, and one re-settle pass: about 1,460 each
    // on 2026-09-23. It was 98,000 when every row was regrouped and every
    // cluster repaired on its own.
    expect($deleted[1])->toBeLessThan(2000)
        ->and($restored[1])->toBeLessThan(2000);
});
