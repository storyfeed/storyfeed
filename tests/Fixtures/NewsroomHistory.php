<?php

namespace Storyfeed\Tests\Fixtures;

use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Body\Excerpt;
use Storyfeed\Body\FileAttachment;
use Storyfeed\Body\Image;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\Grouping\MultiAxisStrategy;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/** Newsroom-shaped person-days: nine people, 36 members, six verbs, body-bearing roles.
 * Based on the demo seeder/simulator and lane filter; uses only disposable test data. */
final class NewsroomHistory
{
    public static function feed(FeedBuilder $builder): FeedBuilder
    {
        return $builder->declareFeed('studio')->query(
            fn ($query) => $query->where(fn ($lane) => $lane->whereNull('data->lane')->orWhere('data->lane', '!=', 'docs')),
        );
    }

    public static function configure(): void
    {
        Storyfeed::feeds(['studio' => self::feed(...)]);
        Delivery::$mintsBody = fn () => Image::make()->caption('Fresh studio preview');
    }

    public static function seed(int $size = 100_000, int $days = 0): void
    {
        $actors = collect(range(1, 9))->map(fn ($i) => User::create(['name' => "Person {$i}", 'email' => "person{$i}@example.com"]));
        $targets = collect(range(1, 9))->map(fn ($i) => Customer::create(['name' => "Project {$i}"]));
        $objects = collect(range(1, 120))->map(fn ($i) => Delivery::create(['tracking_number' => "Document {$i}"]));
        $snapshot = new SnapshotEntity;
        $actorSnapshots = $actors->map(fn ($model) => $snapshot($model)->getKey());
        $targetSnapshots = $targets->map(fn ($model) => $snapshot($model)->getKey());
        $objectSnapshots = $objects->map(fn ($model) => $snapshot($model)->getKey());
        $strategy = new MultiAxisStrategy;
        $days = (int) ceil($size / (36 * 9));
        $start = now()->startOfDay()->subDays($days)->addHours(10);
        foreach (DB::table('feed_snapshots')->get() as $stored) {
            $body = match ($stored->id % 3) {
                0 => Image::make()->caption('Studio reference')->width(1200)->height(800),
                1 => FileAttachment::make(327680, 'application/pdf', 'Brief.pdf'),
                2 => Excerpt::make(str_repeat('A concrete comment on the current draft. ', 8)),
            };
            DB::table('feed_snapshots')->where('id', $stored->id)->update([
                'body' => json_encode([$body->toPayload()], JSON_THROW_ON_ERROR),
            ]);
        }
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
            DB::beginTransaction();
        }
        try {
            for ($offset = 1; $offset <= $size; $offset += $chunkSize) {
                $chunk = range($offset, min($size, $offset + $chunkSize - 1));
                $activities = [];
                $groupings = [];
                $hashCache = [];
                foreach ($chunk as $id) {
                    $i = $id - 1;
                    $run = intdiv($i, 36);
                    $actor = $run % 9;
                    $target = ($run * 5) % 9;
                    $object = ($i * 13 + intdiv($i, 120)) % 120;
                    $composite = $parent = $anonymous = false;
                    $at = $start->copy()->addDays(intdiv($run, 9))->addSeconds(($run % 9) * 180 + $i % 36)->format('Y-m-d H:i:s.u');
                    $uid = str_pad((string) $id, 26, '0', STR_PAD_LEFT);
                    $row = [
                        'id' => $id, 'uid' => $uid,
                        'verb' => ['comment', 'add', 'upload', 'revise', 'complete', 'assign'][$i % 6],
                        'actor_type' => $anonymous ? null : 'user', 'actor_id' => $anonymous ? null : (string) $actors[$actor]->id,
                        'cached_actor_id' => $anonymous ? null : $actorSnapshots[$actor],
                        'object_type' => $parent ? null : 'delivery', 'object_id' => $parent ? null : (string) $objects[$object]->id,
                        'cached_object_id' => $parent ? null : $objectSnapshots[$object],
                        'target_type' => 'customer', 'target_id' => (string) $targets[$target]->id, 'cached_target_id' => $targetSnapshots[$target],
                        'context_type' => 'customer', 'context_id' => (string) $targets[($target + 1) % 9]->id, 'cached_context_id' => $targetSnapshots[($target + 1) % 9],
                        'origin_type' => $i % 6 === 3 ? 'delivery' : null, 'origin_id' => $i % 6 === 3 ? (string) $objects[($object + 1) % 120]->id : null, 'cached_origin_id' => $i % 6 === 3 ? $objectSnapshots[($object + 1) % 120] : null,
                        'result_type' => $i % 6 === 3 ? 'delivery' : null, 'result_id' => $i % 6 === 3 ? (string) $objects[$object]->id : null, 'cached_result_id' => $i % 6 === 3 ? $objectSnapshots[$object] : null,
                        'instrument_type' => $i % 6 === 5 ? 'user' : null, 'instrument_id' => $i % 6 === 5 ? (string) $actors[($actor + 1) % 9]->id : null, 'cached_instrument_id' => $i % 6 === 5 ? $actorSnapshots[($actor + 1) % 9] : null,
                        'data' => json_encode(['lane' => $i % 36 === 35 ? 'docs' : 'studio', 'revision' => $i % 7, 'note' => 'Recorded studio activity'], JSON_THROW_ON_ERROR),
                        'published_at' => $at, 'created_at' => $at, 'updated_at' => $at,
                    ];
                    $activities[] = $row;

                    $hashKey = implode('|', [$row['verb'], $row['actor_id'], $row['object_id'], $row['target_id'], $row['context_id'], $row['origin_id'], $row['result_id'], $row['instrument_id'], substr($at, 0, 10)]);
                    $hashes = $hashCache[$hashKey] ??= $strategy->hashes(new Activity($row));
                    foreach ($hashes as $bucket => $hash) {
                        // Include inferred winners and unstamped repeat fallback history.
                        $winner = $bucket === 'object';
                        $groupings[] = ['activity_id' => $id, 'bucket' => $bucket, 'hash' => $hash, 'winner' => str_starts_with($bucket, 'summary.') ? null : $winner];
                    }
                }
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

            if ($sqlite) {
                DB::commit();
            }
        } catch (\Throwable $error) {
            if ($sqlite) {
                DB::rollBack();
            }
            throw $error;
        } finally {
            if ($sqlite) {
                DB::statement('pragma cache_size = '.$cacheSize);
            }
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::select("select setval(pg_get_serial_sequence('feed_activities', 'id'), ?, true)", [$size]);
            DB::statement('analyze feed_activities');
            DB::statement('analyze feed_groupings');
        } elseif (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::select('analyze table feed_activities, feed_groupings');
        }
    }
}
