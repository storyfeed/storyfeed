<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Actions\PruneActivities;

class PruneCommand extends Command
{
    protected $signature = 'storyfeed:prune
        {--days= : Override storyfeed.prune.after_days (a verb\'s own window still wins)}
        {--pretend : Report what would be deleted, per verb, without deleting it}';

    protected $description = 'Permanently delete activities older than their verb\'s retention window, and the snapshots only they referenced';

    public function handle(): int
    {
        $days = $this->option('days');
        $pretend = (bool) $this->option('pretend');

        $result = (new PruneActivities)($days === null ? null : (int) $days, $pretend);

        if (! $result['enabled']) {
            $this->warn('Pruning is disabled: set storyfeed.prune.after_days, declare ->keepFor() on a verb, or pass --days.');

            return self::SUCCESS;
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

        return self::SUCCESS;
    }
}
