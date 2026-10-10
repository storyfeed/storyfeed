<?php

namespace Storyfeed\Actions;

use Carbon\CarbonInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Storyfeed\Models\Activity;
use Storyfeed\Support\Chronology;

/**
 * Delete burst windows that closed more than
 * `storyfeed.grouping.burst_retention_days` days ago (#91).
 *
 * `feed_grouping_bursts` is write-path working state: one row per grouping
 * key, holding the key's latest window, consulted only when a new activity
 * is recorded. Memberships live in `feed_groupings`, so reads never see
 * this table and a sweep cannot change one. A window is closed once
 * `last_activity_at + within_seconds` or `opened_at + ceiling_seconds` has
 * passed; after that, the only activity that could still join it is one
 * recorded late with a `published_at` inside it. The grace period keeps
 * those joining for a week; past it, a late activity opens its own group,
 * and `storyfeed:curate --rebuild-bursts` still regroups history.
 *
 * The test is exact per window policy: one pass per distinct
 * (within, ceiling) pair, so each row is judged by its own stored window.
 * Each chunk is its own statement, with no transaction or lock held across
 * chunks. A publisher that touched a row inside the grace period stamped
 * its `locked_at`, and the delete re-checks that column: on MySQL, MariaDB
 * and PostgreSQL a delete waiting on a publisher's row lock re-reads the row
 * once it commits, and SQLite serialises writers, so a window a publisher
 * just reopened is never swept from under it.
 *
 * @internal
 */
final class SweepGroupingBursts
{
    public const CHUNK = 1000;

    /** @return int windows swept, or that would be with `$pretend` */
    public function __invoke(bool $pretend = false, ?CarbonInterface $asOf = null): int
    {
        $days = self::retentionDays();

        $connection = (new (config('storyfeed.models.activity', Activity::class)))->getConnection();

        if ($days === null || ! $connection->getSchemaBuilder()->hasTable(config('storyfeed.tables.grouping_bursts', 'feed_grouping_bursts'))) {
            return 0;
        }

        $cutoff = ($asOf ?? Carbon::now())->toImmutable()->subDays($days);
        $swept = 0;

        foreach ($this->policies($connection) as [$within, $ceiling]) {
            $closed = fn (Builder $query) => $this->closed($query, $cutoff, $within, $ceiling);

            if ($pretend) {
                $swept += $this->table($connection)->where($closed)->count();

                continue;
            }

            $after = null;

            do {
                $keys = $this->table($connection)->where($closed)
                    ->when($after !== null, fn (Builder $query) => $query->where('key', '>', $after))
                    ->orderBy('key')->limit(self::CHUNK)->pluck('key')->all();

                if ($keys === []) {
                    break;
                }

                $swept += $this->table($connection)->whereIn('key', $keys)->where($closed)->delete();
                $after = end($keys);
            } while (count($keys) === self::CHUNK);
        }

        return $swept;
    }

    /** The configured grace, in whole days: null keeps every window. */
    public static function retentionDays(): ?int
    {
        $days = config('storyfeed.grouping.burst_retention_days', 7);

        if ($days === null) {
            return null;
        }

        if (! is_numeric($days) || (int) $days < 1) {
            throw new InvalidArgumentException('storyfeed.grouping.burst_retention_days must be a whole number of days, at least 1, or null to keep every burst window.');
        }

        return (int) $days;
    }

    /**
     * Every stored window policy, and [null, null] for rows a publish never
     * finished writing.
     *
     * @return list<array{int|null, int|null}>
     */
    private function policies(ConnectionInterface $connection): array
    {
        $policies = $this->table($connection)->whereNotNull('opened_at')
            ->select('within_seconds', 'ceiling_seconds')->distinct()->get()
            ->map(fn (object $row) => [(int) $row->within_seconds, (int) $row->ceiling_seconds])
            ->all();

        return [...$policies, [null, null]];
    }

    private function closed(Builder $query, CarbonInterface $cutoff, ?int $within, ?int $ceiling): void
    {
        $query->where(fn (Builder $query) => $query->whereNull('locked_at')->orWhere('locked_at', '<', $cutoff->format('Y-m-d H:i:s')));

        if ($within === null || $ceiling === null) {
            $query->whereNull('opened_at');

            return;
        }

        $query->where('within_seconds', $within)->where('ceiling_seconds', $ceiling)
            ->where(fn (Builder $query) => $query
                ->where('last_activity_at', '<', Chronology::stamp($cutoff->subSeconds($within)))
                ->orWhere('opened_at', '<', Chronology::stamp($cutoff->subSeconds($ceiling))));
    }

    private function table(ConnectionInterface $connection): Builder
    {
        return $connection->table(config('storyfeed.tables.grouping_bursts', 'feed_grouping_bursts'));
    }
}
