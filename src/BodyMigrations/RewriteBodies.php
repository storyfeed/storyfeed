<?php

namespace Storyfeed\BodyMigrations;

use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\SyncToken;

final class RewriteBodies
{
    /** @return array<string, list<string>> */
    public function columns(): array
    {
        $activity = new (config('storyfeed.models.activity', Activity::class));
        $snapshot = new (config('storyfeed.models.snapshot', Snapshot::class));

        $party = new (config('storyfeed.models.party', Party::class));

        return [$activity->getTable() => ['data'], $snapshot->getTable() => ['data', 'body'], $party->getTable() => ['data']];
    }

    /** @param (Closure(int, int): void)|null $afterChunk
     * @return array{scanned: int, changed: int, chunks: int}
     */
    public function run(int $chunk = 500, ?Closure $afterChunk = null): array
    {
        if ($chunk < 1) {
            throw new InvalidArgumentException('The chunk size must be positive.');
        }
        $registry = app(BodyRegistry::class);
        $scanned = $changed = $chunks = 0;
        foreach ($this->columns() as $table => $columns) {
            $last = 0;
            $ceiling = DB::table($table)->max('id') ?? 0;
            do {
                $count = DB::transaction(function () use ($table, $columns, $ceiling, $chunk, $registry, &$last, &$scanned, &$changed): int {
                    // Read under the write lock: a snapshot refresh must never be overwritten by a stale chunk.
                    $rows = DB::table($table)->where('id', '>', $last)->where('id', '<=', $ceiling)->orderBy('id')->limit($chunk)->lockForUpdate()->get();
                    $chunkChanged = false;
                    foreach ($rows as $row) {
                        $updates = [];
                        foreach ($columns as $column) {
                            $raw = $row->$column;
                            if ($raw === null) {
                                continue;
                            }
                            $old = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                            $new = $registry->walk($old, persist: true);
                            if ($new !== $old) {
                                $updates[$column] = json_encode($new, JSON_THROW_ON_ERROR);
                            }
                        }
                        if ($updates !== []) {
                            DB::table($table)->where('id', $row->id)->update($updates);
                            $changed++;
                            $chunkChanged = true;
                        }
                        $last = $row->id;
                        $scanned++;
                    }

                    if ($chunkChanged) {
                        SyncToken::bump();
                    }

                    return $rows->count();
                });
                if ($count > 0) {
                    $chunks++;
                    $afterChunk?->__invoke($chunks, $changed);
                }
            } while ($count === $chunk);
        }

        return compact('scanned', 'changed', 'chunks');
    }
}
