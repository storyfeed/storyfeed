<?php

namespace Storyfeed\Actions;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Laravel\SerializableClosure\SerializableClosure;
use RuntimeException;
use Storyfeed\Grouping\Axis;
use Storyfeed\Grouping\MultiAxisStrategy;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Meta;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\BurstRebuildLock;
use Storyfeed\Support\Chronology;
use Storyfeed\Support\SyncToken;

/**
 * Offline history migration. Keep readers and ALL publishers paused until completion.
 *
 * @internal
 */
final class RebuildGroupingBursts
{
    private const STATE = 'rebuild:bursts:cursor';

    private const POLICY = 'rebuild:bursts:policy';

    /**
     * @param  (Closure(int, int, string): void)|null  $progress  Called after a chunk commits.
     * @return array{processed: int, restamped: int, rehashed: int}
     */
    public function __invoke(bool $resume = false, int $batchSize = 500, ?Closure $progress = null, bool $restart = false): array
    {
        if ($batchSize < 1 || $batchSize > 1000 || ($resume && $restart)) {
            throw new RuntimeException('Use a batch size between 1 and 1000; --resume and --restart are mutually exclusive.');
        }
        $activityClass = $this->activityModel()::class;
        $groupingClass = config('storyfeed.models.grouping', Grouping::class);
        $metaClass = config('storyfeed.models.meta', Meta::class);
        $connection = (new $activityClass)->getConnection();
        $meta = (new $metaClass)->getTable();

        return (new BurstRebuildLock)->run($connection, $meta, function () use ($resume, $restart, $batchSize, $progress, $activityClass, $groupingClass, $connection, $meta): array {
            $manager = app(StoryfeedManager::class);
            $axes = $manager->registeredAxes();
            $sequential = config('storyfeed.grouping.strategy', MultiAxisStrategy::class) !== MultiAxisStrategy::class || array_any($axes, fn (Axis $axis) => ! $axis->usesBursts() && ! $axis->isRowBacked());
            $policy = $this->policy($activityClass, $manager);
            $value = $connection->table($meta)->useWritePdo()->where('key', self::STATE)->value('value');
            if ($value !== null && ! $resume && ! $restart) {
                throw new RuntimeException('An interrupted burst rebuild exists. Keep writers paused and use --resume (or --restart to discard its progress).');
            }
            if ($resume && $value === null) {
                throw new RuntimeException('There is no interrupted burst rebuild to --resume.');
            }
            if ($resume) {
                $state = json_decode($value, true, flags: JSON_THROW_ON_ERROR);
                if ($connection->table($meta)->useWritePdo()->where('key', self::POLICY)->value('value') !== $policy) {
                    throw new RuntimeException('The grouping policy changed; restore it before --resume or use --restart.');
                }
                $this->guardHistory($activityClass, $state, true);
            } else {
                $state = ['phase' => 'replay', 'at' => null, 'id' => null, 'done' => 0, 'stamped' => 0, 'hashed' => 0,
                    'total' => $activityClass::withTrashed()->useWritePdo()->count(), 'max' => $activityClass::withTrashed()->useWritePdo()->max('id'),
                    'started' => Chronology::stamp(now())];
                $connection->transaction(function () use ($connection, $meta, $groupingClass, $axes, $state, $policy): void {
                    $burstAxes = array_keys(array_filter($axes, fn (Axis $axis) => $axis->usesBursts()));
                    $groupingClass::query()->whereIn('bucket', [...$burstAxes, 'summary.hour', 'summary.day', 'summary.week', 'summary.month'])->delete();
                    $connection->table(config('storyfeed.tables.grouping_bursts', 'feed_grouping_bursts'))->delete();
                    $this->save($connection, $meta, self::POLICY, $policy);
                    $this->save($connection, $meta, self::STATE, json_encode($state, JSON_THROW_ON_ERROR));
                    // Readers must discard old pages even if this process dies mid-replay.
                    SyncToken::bump();
                });
            }
            while ($state['phase'] === 'replay') {
                $this->guardHistory($activityClass, $state);
                $activities = $this->after($activityClass::withTrashed()->useWritePdo(), $state, $connection->getDriverName())
                    ->orderBy('published_at')->orderBy('id')->limit($batchSize)->get();
                $state = $connection->transaction(function () use ($activities, $state, $sequential, $connection, $meta): array {
                    if ($activities->isEmpty()) {
                        $state['phase'] = 'curate';
                        $state['id'] = null;
                        $state['done'] = 0;
                    } else {
                        $stats = (new ReplayGroupingBursts)($activities, $state['started'], $sequential);
                        $state['done'] += $activities->count();
                        $state['hashed'] += $stats['rehashed'];
                        $state['stamped'] += $stats['restamped'];
                        $state['at'] = $activities->last()->getRawOriginal('published_at');
                        $state['id'] = $activities->last()->getKey();
                    }
                    $this->save($connection, $meta, self::STATE, json_encode($state, JSON_THROW_ON_ERROR));

                    return $state;
                });
                if ($state['phase'] === 'replay') {
                    $progress?->__invoke($state['done'], $state['total'], 'replay');
                }
            }
            if (! $sequential) {
                while (true) {
                    $this->guardHistory($activityClass, $state);
                    $ids = $activityClass::query()->useWritePdo()->when($state['id'] !== null, fn ($q) => $q->where('id', '>', $state['id']))
                        ->orderBy('id')->limit($batchSize)->pluck('id')->all();
                    if ($ids === []) {
                        break;
                    }
                    $state = $connection->transaction(function () use ($ids, $state, $connection, $meta): array {
                        $state['stamped'] += (new CurateCluster)->settleMany($ids);
                        $state['id'] = end($ids);
                        $state['done'] += count($ids);
                        $this->save($connection, $meta, self::STATE, json_encode($state, JSON_THROW_ON_ERROR));

                        return $state;
                    });
                    $progress?->__invoke($state['done'], $state['total'], 'curate');
                }
            }
            $this->guardHistory($activityClass, $state, true);
            $connection->transaction(function () use ($connection, $meta): void {
                SyncToken::bump();
                $connection->table($meta)->whereIn('key', [self::STATE, self::POLICY])->delete();
            });

            return ['processed' => $state['total'], 'restamped' => $state['stamped'], 'rehashed' => $state['hashed']];
        });
    }

    /**
     * @param  Builder<Activity>  $query
     * @param  array<string, mixed>  $state
     * @return Builder<Activity>
     */
    private function after(Builder $query, array $state, string $driver): Builder
    {
        if ($state['id'] === null) {
            return $query;
        }

        return $query->where(function ($q) use ($state, $driver): void {
            if ($state['at'] === null) {
                $q->where(fn ($q) => $q->whereNull('published_at')->where('id', '>', $state['id']));
                if ($driver !== 'pgsql') {
                    $q->orWhereNotNull('published_at');
                }
            } else {
                $q->where('published_at', '>', $state['at'])
                    ->orWhere(fn ($q) => $q->where('published_at', $state['at'])->where('id', '>', $state['id']));
                if ($driver === 'pgsql') {
                    $q->orWhereNull('published_at');
                }
            }
        });
    }

    /**
     * @param  class-string<Activity>  $activity
     * @param  array<string, mixed>  $state
     */
    private function guardHistory(string $activity, array $state, bool $count = false): void
    {
        if ((string) $activity::withTrashed()->useWritePdo()->max('id') !== (string) $state['max']
            || ($count && $activity::withTrashed()->useWritePdo()->count() !== $state['total'])) {
            throw new RuntimeException('Activity history changed during the rebuild. Pause all writers; restore the backup or use --restart.');
        }
    }

    private function activityModel(): Activity
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return new $model;
    }

    private function save(Connection $connection, string $table, string $key, string $value): void
    {
        $connection->table($table)->upsert(['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()], ['key'], ['value', 'updated_at']);
    }

    /** @param class-string<Activity> $activity */
    private function policy(string $activity, StoryfeedManager $manager): string
    {
        $axes = array_map(function (Axis $axis): array {
            return array_map(fn ($value) => $value instanceof Closure ? new SerializableClosure($value) : $value, (array) $axis);
        }, $manager->registeredAxes());
        $windows = [];
        foreach ($activity::withTrashed()->useWritePdo()->select(['object_type', 'verb'])->distinct()->get() as $row) {
            $windows[$row->object_type.'.'.$row->verb] = $manager->burstWindow($row->object_type, $row->verb);
        }
        ksort($windows);

        return hash('sha256', serialize([$axes, $windows, config('storyfeed.grouping'), config('storyfeed.models'), config('storyfeed.tables')]));
    }
}
