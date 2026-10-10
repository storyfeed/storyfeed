<?php

namespace Storyfeed\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Storyfeed\Grouping\MultiAxisStrategy;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\Chronology;

/**
 * Offline replay only: the rebuild owns the database while publishers are paused.
 *
 * @internal
 */
final class ReplayGroupingBursts
{
    /**
     * @param  Collection<int, Activity>  $activities
     * @return array{rehashed: int, restamped: int}
     */
    public function __invoke(Collection $activities, string $startedAt, bool $sequential): array
    {
        $connection = $activities->first()->getConnection();
        $grouping = config('storyfeed.models.grouping', Grouping::class);
        $model = new $grouping;
        $manager = app(StoryfeedManager::class);
        $strategy = app(config('storyfeed.grouping.strategy', MultiAxisStrategy::class));
        $table = config('storyfeed.tables.grouping_bursts', 'feed_grouping_bursts');
        $existing = $grouping::query()->whereIn('activity_id', $activities->map(fn (Activity $activity) => $activity->getKey())->all())->toBase()->get()->groupBy('activity_id');
        $recipes = [];
        $keys = [];
        foreach ($activities as $activity) {
            $own = ($existing->get($activity->getKey()) ?? collect())->keyBy('bucket');
            $claim = $own->get('composite')?->hash;
            if ($claim !== null && $claim !== $activity->uid) {
                continue;
            }
            $hashes = $claim === null ? $strategy->hashes($activity) : [];
            $recipes[$activity->getKey()] = $hashes;
            foreach ($hashes as $axis => $logical) {
                if ($manager->axis($axis)?->usesBursts()) {
                    $keys[hash('sha256', $axis."\x1f".$logical)] = true;
                }
            }
        }
        $states = [];
        foreach (array_chunk(array_keys($keys), 500) as $chunk) {
            foreach ($connection->table($table)->whereIn('key', $chunk)->get() as $state) {
                $states[$state->key] = (array) $state;
            }
        }
        $inserts = [];
        $deletes = [];
        $now = now()->format('Y-m-d H:i:s');
        $rehashed = $restamped = 0;
        $curate = new CurateCluster(function (bool $changed) use (&$restamped): void {
            $restamped += (int) $changed;
        });
        foreach ($activities as $activity) {
            $id = $activity->getKey();
            $hashes = $recipes[$id] ?? null;
            if ($hashes !== null) {
                [$within, $ceiling] = $manager->burstWindow($activity->object_type, (string) $activity->verb);
                $at = Chronology::stamp($activity->published_at ?? $startedAt);
                $atMicros = self::micros($at);
                $hasBurst = false;
                foreach ($hashes as $axis => &$logical) {
                    if (! $manager->axis($axis)?->usesBursts()) {
                        continue;
                    }
                    $hasBurst = true;
                    $key = hash('sha256', $axis."\x1f".$logical);
                    $state = $states[$key] ?? null;
                    $opened = isset($state['opened_at']) ? self::micros($state['opened_at']) : null;
                    $last = isset($state['last_activity_at']) ? self::micros($state['last_activity_at']) : null;
                    $joins = $opened !== null && $last !== null
                        && (int) $state['within_seconds'] === $within && (int) $state['ceiling_seconds'] === $ceiling
                        && $atMicros >= $opened && $atMicros < $last + $within * 1_000_000
                        && $atMicros < $opened + $ceiling * 1_000_000;
                    $logical = $joins ? $state['hash'] : 'b1:'.$key.':'.$activity->uid;
                    if ($opened !== null && $atMicros < $opened) {
                        continue;
                    }
                    $states[$key] = ['key' => $key, 'hash' => $logical,
                        'opened_at' => $joins ? $state['opened_at'] : $at,
                        'last_activity_at' => $joins && $last > $atMicros ? $state['last_activity_at'] : $at,
                        'within_seconds' => $within, 'ceiling_seconds' => $ceiling, 'locked_at' => $now];
                }
                unset($logical);
                $rehashed += (int) $hasBurst;
                foreach ($hashes as $bucket => $hash) {
                    $inserts[] = ['activity_id' => $id, 'bucket' => $bucket, 'hash' => $hash, 'winner' => null, 'created_at' => $now, 'updated_at' => $now];
                }
                foreach ($existing->get($id) ?? [] as $row) {
                    if ($row->bucket !== null && ! isset($hashes[$row->bucket]) && ! in_array($row->bucket, $manager->rowBackedBuckets(), true)) {
                        $deletes[] = $row->{$model->getKeyName()};
                    }
                }
            }
            // A tombstone's own winner reflects its chronological prefix:
            // later threshold sweeps never revisit soft-deleted activities.
            // Nonburst custom axes can see future memberships, so preserve
            // their original sequential decisions too.
            if ($sequential || $activity->trashed()) {
                $this->flush($inserts, $deletes);
                $curate($activity);
            }
        }
        $this->flush($inserts, $deletes);
        foreach (array_chunk(array_values($states), 100) as $rows) {
            $connection->table($table)->upsert($rows, ['key'], ['hash', 'opened_at', 'last_activity_at', 'within_seconds', 'ceiling_seconds', 'locked_at']);
        }

        return ['rehashed' => $rehashed, 'restamped' => $restamped];
    }

    /**
     * @param  list<array{activity_id: int|string, bucket: string, hash: string, winner: null, created_at: string, updated_at: string}>  $inserts
     * @param  list<int|string>  $deletes
     */
    private function flush(array &$inserts, array &$deletes): void
    {
        $grouping = config('storyfeed.models.grouping', Grouping::class);
        foreach (array_chunk($inserts, 100) as $rows) {
            // Existing nonburst memberships keep their winner, as updateOrCreate does.
            $grouping::query()->upsert($rows, ['activity_id', 'bucket'], ['hash', 'updated_at']);
        }
        foreach (array_chunk($deletes, 500) as $ids) {
            $grouping::query()->whereKey($ids)->delete();
        }
        $inserts = $deletes = [];
    }

    private static function micros(string $stamp): int
    {
        return (int) Carbon::parse($stamp)->format('Uu');
    }
}
