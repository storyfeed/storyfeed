<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Actions\PruneActivities;
use Storyfeed\Actions\SweepGroupingBursts;

class PruneCommand extends Command
{
    protected $signature = 'storyfeed:prune
        {--days= : Override storyfeed.prune.after_days (a verb\'s own window still wins)}
        {--pretend : Report what would be deleted, per verb, without deleting it}
        {--bursts : Only sweep closed burst windows (storyfeed.grouping.burst_retention_days); never deletes activities}';

    protected $description = 'Permanently delete activities older than their verb\'s retention window, the snapshots only they referenced, and closed burst windows';

    public function handle(): int
    {
        $pretend = (bool) $this->option('pretend');

        if (! $this->option('bursts')) {
            $this->prune($pretend);
        }

        // Write-path working state, swept whether or not activities are
        // pruned: reads never touch it (SweepGroupingBursts).
        if (SweepGroupingBursts::retentionDays() !== null) {
            $swept = number_format((new SweepGroupingBursts)($pretend));

            $this->info($pretend ? "Would sweep {$swept} closed burst windows." : "Swept {$swept} closed burst windows.");
        }

        return self::SUCCESS;
    }

    protected function prune(bool $pretend): void
    {
        $days = $this->option('days');

        $result = (new PruneActivities)($days === null ? null : (int) $days, $pretend);

        if (! $result['enabled']) {
            $this->warn('Pruning is disabled: set storyfeed.prune.after_days, declare ->keepFor() on a verb, or pass --days.');

            return;
        }

        if ($result['verbs'] !== []) {
            $this->table(['Verb', 'Activities'], array_map(
                fn (string $verb, int $count) => [$verb, number_format($count)],
                array_keys($result['verbs']),
                $result['verbs'],
            ));
        }

        $what = "{$result['pruned']} activities, {$result['snapshots']} snapshots and {$result['tombstones']} tombstones";

        $this->info($pretend ? "Would prune {$what}. Nothing was deleted." : "Pruned {$what}.");
    }
}
