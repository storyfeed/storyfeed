<?php

namespace Storyfeed\Support;

use Illuminate\Support\Str;
use InvalidArgumentException;
use Storyfeed\Models\Meta;

/** Recent completed passes, recorded at execution time; never reconstructed. */
class MaintenanceHistory
{
    public const LIMIT = 20;

    /** @param array<string, int> $counts */
    public static function record(string $command, array $counts): void
    {
        $expected = match ($command) {
            'curate' => ['processed', 'restamped', 'rehashed'],
            'trickle' => ['snapshotted', 'pruned', 'unresolved', 'reshaped'],
            default => throw new InvalidArgumentException('Unknown maintenance command.'),
        };

        if (array_keys($counts) !== $expected || array_any($counts, fn ($count) => $count < 0)) {
            throw new InvalidArgumentException('Invalid maintenance counts.');
        }

        $prefix = self::prefix($command);

        (new Meta)->getConnection()->transaction(function () use ($prefix, $counts) {
            // One key per pass avoids concurrent writers replacing one another's
            // history array. The compact value fits feed_meta's existing varchar.
            Meta::query()->create([
                'key' => $prefix.Str::ulid(),
                'value' => json_encode($counts, JSON_THROW_ON_ERROR),
            ]);

            $oldest = Meta::query()->where('key', 'like', $prefix.'%')
                ->orderByDesc('id')->skip(self::LIMIT - 1)->value('id');

            if ($oldest !== null) {
                Meta::query()->where('key', 'like', $prefix.'%')->where('id', '<', $oldest)->delete();
            }
        });
    }

    /** @return list<array<string, int|string>> Oldest to newest within the retained series. */
    public static function recent(string $command): array
    {
        return Meta::query()->where('key', 'like', self::prefix($command).'%')
            ->orderByDesc('id')->limit(self::LIMIT)->get()->reverse()->values()
            ->map(fn (Meta $row) => [
                'at' => $row->created_at->toIso8601String(),
                ...json_decode($row->value, true, flags: JSON_THROW_ON_ERROR),
            ])->all();
    }

    private static function prefix(string $command): string
    {
        if (! in_array($command, ['curate', 'trickle'], true)) {
            throw new InvalidArgumentException('Unknown maintenance command.');
        }

        return 'maintenance:'.$command.':';
    }
}
