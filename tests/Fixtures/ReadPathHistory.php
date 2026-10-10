<?php

namespace Storyfeed\Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\MultiAxisStrategy;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/** A deterministic studio history, bulk inserted so publishing is not the benchmark. */
final class ReadPathHistory
{
    public static function seed(int $size = 100_000, int $days = 120): void
    {
        if (DB::transactionLevel() !== 0) {
            throw new \LogicException('The read-path seed must own its batch transactions.');
        }

        $actors = collect(range(1, 24))->map(fn ($i) => User::create(['name' => "Person {$i}", 'email' => "person{$i}@example.com"]));
        $targets = collect(range(1, 9))->map(fn ($i) => Customer::create(['name' => "Project {$i}"]));
        $objects = collect(range(1, 120))->map(fn ($i) => Delivery::create(['tracking_number' => "Document {$i}"]));
        // A quiet project: the context of five activities across history.
        $archive = Customer::create(['name' => 'Archive']);
        $snapshot = new SnapshotEntity;
        $archiveSnapshot = $snapshot($archive)->getKey();
        $actorSnapshots = $actors->map(fn ($model) => $snapshot($model)->getKey());
        $targetSnapshots = $targets->map(fn ($model) => $snapshot($model)->getKey());
        $objectSnapshots = $objects->map(fn ($model) => $snapshot($model)->getKey());
        $strategy = new MultiAxisStrategy;
        $burstState = [];
        $start = now()->subDays($days);
        $started = hrtime(true);
        $progress = (bool) env('STORYFEED_READ_SEED_PROGRESS');

        $sqlite = DB::getDriverName() === 'sqlite';
        $chunkSize = $sqlite ? 1000 : 250;
        // The disposable SQLite fixture otherwise spends most of its seed
        // rewriting index pages under its tiny default cache. Restore the
        // original cache before measuring reads; the production path is unchanged.
        $cacheSize = $sqlite ? (int) DB::selectOne('pragma cache_size')->cache_size : null;
        if ($sqlite) {
            DB::statement('pragma cache_size = -65536');
        }
        try {
            for ($offset = 1; $offset <= $size; $offset += $chunkSize) {
                $chunk = range($offset, min($size, $offset + $chunkSize - 1));
                $activities = [];
                $groupings = [];
                $hashCache = [];
                foreach ($chunk as $id) {
                    $i = $id - 1;
                    $run = intdiv($i, 4);
                    $actor = ($run * 7 + intdiv($run, 24)) % 24;
                    $target = (intdiv($run, 7) * 5) % 9;
                    $object = ($run * 13 + intdiv($run, 120)) % 120;
                    $composite = $i % 100 < 4;
                    $parent = $composite && $i % 100 === 0;
                    $anonymous = ! $composite && $i % 997 === 0;
                    $at = $start->copy()->addSeconds(intdiv($i * $days * 86400, $size))->format('Y-m-d H:i:s.u');
                    $uid = str_pad((string) $id, 26, '0', STR_PAD_LEFT);
                    $row = [
                        'id' => $id, 'uid' => $uid,
                        'verb' => $composite ? 'upload' : ['revise', 'comment', 'approve', 'dispatch', 'confirm', 'note'][($run + intdiv($run, 24)) % 6],
                        'actor_type' => $anonymous ? null : 'user', 'actor_id' => $anonymous ? null : (string) $actors[$actor]->id,
                        'cached_actor_id' => $anonymous ? null : $actorSnapshots[$actor],
                        'object_type' => $parent ? null : 'delivery', 'object_id' => $parent ? null : (string) $objects[$object]->id,
                        'cached_object_id' => $parent ? null : $objectSnapshots[$object],
                        'target_type' => 'customer', 'target_id' => (string) $targets[$target]->id, 'cached_target_id' => $targetSnapshots[$target],
                        'context_type' => null, 'context_id' => null, 'cached_context_id' => null,
                        'published_at' => $at, 'created_at' => $at, 'updated_at' => $at,
                    ];
                    if ($i % max(1, intdiv($size, 5)) === 7) {
                        [$row['context_type'], $row['context_id'], $row['cached_context_id']] = ['customer', (string) $archive->id, $archiveSnapshot];
                    }
                    $activities[] = $row;

                    if ($composite) {
                        $groupings[] = ['activity_id' => $id, 'bucket' => 'composite', 'hash' => str_pad((string) ($id - $i % 100), 26, '0', STR_PAD_LEFT), 'winner' => $parent ? null : true];
                        if (! $parent) {
                            continue;
                        }
                    }
                    // Sparse legacy rows exercise the negative solo lookup, not just the happy path.
                    if ($i % 991 === 0 && ! $composite) {
                        continue;
                    }
                    $hashKey = implode('|', [$row['verb'], $row['actor_id'], $row['object_id'], $row['target_id'], $row['context_id'], substr($at, 0, 10)]);
                    $hashes = $hashCache[$hashKey] ??= $strategy->hashes(new Activity($row));
                    foreach ($hashes as $axis => &$logical) {
                        if (! Storyfeed::axis($axis)?->usesBursts()) {
                            continue;
                        }
                        $key = hash('sha256', $axis."\x1f".$logical);
                        $atSeconds = strtotime($at);
                        $state = $burstState[$key] ?? null;
                        if ($state === null || $atSeconds - $state['last'] >= 900 || $atSeconds - $state['first'] >= 14400) {
                            $state = ['first' => $atSeconds, 'hash' => 'b1:'.$key.':'.$uid];
                        }
                        $state['last'] = $atSeconds;
                        $burstState[$key] = $state;
                        $logical = $state['hash'];
                    }
                    unset($logical);
                    foreach ($hashes as $bucket => $hash) {
                        if ($parent && ! str_starts_with($bucket, 'summary.')) {
                            continue;
                        }
                        // Include inferred winners and unstamped repeat fallback history.
                        $winner = $i % 13 === 0 ? null : $bucket === ($run % 3 === 0 ? 'object' : 'repeat');
                        $groupings[] = ['activity_id' => $id, 'bucket' => $bucket, 'hash' => $hash, 'winner' => str_starts_with($bucket, 'summary.') ? null : $winner];
                    }
                }
                // Commit each bounded batch. An outer SQLite transaction keeps
                // every nested savepoint alive for the entire multi-million-row
                // seed, and an automatic rollback then masks the original error
                // with "no such savepoint" while unwinding those savepoints.
                DB::transaction(function () use ($activities, $groupings, $sqlite) {
                    DB::table('feed_activities')->insert($activities);
                    foreach (array_chunk($groupings, $sqlite ? 4000 : 250) as $rows) {
                        DB::table('feed_groupings')->insert($rows);
                    }
                });
                if ($progress && end($chunk) % 100_000 === 0) {
                    fprintf(STDERR, "Seeded %d activities in %.1fs\n", end($chunk), (hrtime(true) - $started) / 1e9);
                }
            }
        } finally {
            if ($sqlite) {
                DB::statement('pragma cache_size = '.$cacheSize);
            }
        }

        self::participants($size);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('analyze feed_activities');
            DB::statement('analyze feed_groupings');
            DB::statement('analyze '.SyncParticipants::table());
        } elseif (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::select('analyze table feed_activities, feed_groupings, '.SyncParticipants::table());
        }
    }

    /** The direct participant rows publish would have written, in bounded batches. */
    protected static function participants(int $size): void
    {
        $table = SyncParticipants::table();
        $columns = ['activity_id', 'role', 'entity_type', 'entity_id', 'distance'];
        $published = Schema::hasColumn($table, 'published_at');
        for ($from = 1; $from <= $size; $from += 100_000) {
            DB::transaction(function () use ($table, $columns, $published, $from) {
                foreach (['actor', 'object', 'target', 'context'] as $role) {
                    DB::table($table)->insertUsing($published ? [...$columns, 'published_at'] : $columns, DB::table('feed_activities')
                        ->whereBetween('id', [$from, $from + 99_999])->whereNotNull("{$role}_id")
                        ->selectRaw("id, '{$role}', {$role}_type, {$role}_id, 0".($published ? ', published_at' : '')));
                }
            });
        }
    }
}
