<?php

namespace Storyfeed\Actions;

use Closure;
use RuntimeException;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Meta;
use Storyfeed\Support\BurstRebuildLock;
use Storyfeed\Support\SyncToken;

/**
 * Offline, chunk-atomic rewrite or gaps-only backfill from current parents.
 *
 * @internal
 */
final class RebuildAncestors
{
    public const STATE = 'rebuild:ancestors:cursor';

    public const MISSING_STATE = 'backfill:ancestors:cursor';

    /**
     * @param  (Closure(int, int): void)|null  $progress
     * @return array{processed: int}
     */
    public function __invoke(bool $resume = false, bool $restart = false, int $batchSize = 500, ?Closure $progress = null, bool $missing = false): array
    {
        if ($batchSize < 1 || $batchSize > 1000 || ($resume && $restart)) {
            throw new RuntimeException('Use a chunk between 1 and 1000; --resume and --restart are mutually exclusive.');
        }
        $activity = config('storyfeed.models.activity', Activity::class);
        $meta = new (config('storyfeed.models.meta', Meta::class));
        $connection = (new $activity)->getConnection();
        $table = $meta->getTable();
        $stateKey = $missing ? self::MISSING_STATE : self::STATE;
        $policy = hash('sha256', serialize([config('storyfeed.ancestors'), config('storyfeed.models'), config('storyfeed.tables')]));

        return (new BurstRebuildLock)->run($connection, $table, function () use ($activity, $connection, $table, $policy, $stateKey, $missing, $resume, $restart, $batchSize, $progress): array {
            $value = $connection->table($table)->useWritePdo()->where('key', $stateKey)->value('value');
            if ($value !== null && ! $resume && ! $restart) {
                throw new RuntimeException('An interrupted ancestor rebuild exists. Keep writers paused and use --resume or --restart.');
            }
            if ($resume && $value === null) {
                throw new RuntimeException('There is no interrupted ancestor rebuild to --resume.');
            }
            $state = $resume ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : [
                'id' => 0, 'done' => 0, 'total' => $activity::withTrashed()->useWritePdo()->count(),
                'max' => $activity::withTrashed()->useWritePdo()->max('id'), 'policy' => $policy,
            ];
            if ($state['policy'] !== $policy) {
                throw new RuntimeException('Ancestor configuration changed. Restore it before --resume or use --restart.');
            }
            $save = function (array $state) use ($connection, $table, $stateKey): void {
                $connection->table($table)->upsert([
                    'key' => $stateKey, 'value' => json_encode($state, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ], ['key'], ['value', 'updated_at']);
            };
            $connection->transaction(function () use ($save, $state): void {
                $save($state);
                SyncToken::bump();
            });
            while (true) {
                if ((string) $activity::withTrashed()->useWritePdo()->max('id') !== (string) $state['max']
                    || $activity::withTrashed()->useWritePdo()->count() !== $state['total']) {
                    throw new RuntimeException('Activity history changed. Pause all writers and use --restart.');
                }
                $rows = $activity::withTrashed()->useWritePdo()->where('id', '>', $state['id'])->orderBy('id')->limit($batchSize)->get();
                if ($rows->isEmpty()) {
                    break;
                }
                $state = $connection->transaction(function () use ($rows, $state, $save, $missing): array {
                    foreach ($rows as $row) {
                        if ($missing) {
                            (new BackfillAncestors)($row);
                        } else {
                            (new SyncParticipants)($row, currentParents: true);
                        }
                    }
                    $state['id'] = $rows->last()->getKey();
                    $state['done'] += $rows->count();
                    $save($state);

                    return $state;
                });
                $progress?->__invoke($state['done'], $state['total']);
            }
            $connection->transaction(function () use ($connection, $table, $stateKey): void {
                $connection->table($table)->where('key', $stateKey)->delete();
                SyncToken::bump();
            });

            return ['processed' => $state['done']];
        });
    }
}
