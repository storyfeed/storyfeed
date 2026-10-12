<?php

namespace Storyfeed\Tests\Fixtures;

use Illuminate\Support\Facades\DB;

final class KeepLatestHistory
{
    /** One superseded row and one live successor on each distinct object key. */
    public static function seedKeys(int $keys = 5000): void
    {
        $rows = [];
        for ($key = 1; $key <= $keys; $key++) {
            foreach ([true, false] as $deleted) {
                $rows[] = [
                    'uid' => str_pad((string) ($key * 2 + (int) $deleted), 26, '0', STR_PAD_LEFT),
                    'object_type' => 'delivery',
                    'object_id' => (string) $key,
                    'verb' => 'save',
                    'published_at' => $deleted ? '2026-09-20 09:00:00' : '2026-09-21 09:00:00',
                    'deleted_at' => $deleted ? '2026-09-21 09:00:00' : null,
                ];
            }
            if (count($rows) === 500) {
                DB::table(config('storyfeed.tables.activities', 'feed_activities'))->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table(config('storyfeed.tables.activities', 'feed_activities'))->insert($rows);
        }
    }

    /** One object deleted with its whole history: every row soft-deleted, none live. */
    public static function seedTombstoned(int $activities = 3000, int $offset = 0): void
    {
        $rows = [];
        for ($row = 1; $row <= $activities; $row++) {
            $rows[] = [
                'uid' => str_pad((string) ($offset + $row), 26, '0', STR_PAD_LEFT),
                'object_type' => 'delivery',
                'object_id' => 'tombstoned',
                'verb' => 'save',
                'published_at' => date('Y-m-d H:i:s', 1_790_000_000 + $row),
                'deleted_at' => '2026-09-21 09:00:00',
            ];
            if (count($rows) === 500) {
                DB::table(config('storyfeed.tables.activities', 'feed_activities'))->insert($rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            DB::table(config('storyfeed.tables.activities', 'feed_activities'))->insert($rows);
        }
    }
}
