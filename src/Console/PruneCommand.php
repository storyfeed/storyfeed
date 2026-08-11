<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Actions\PruneActivities;

class PruneCommand extends Command
{
    protected $signature = 'storyfeed:prune {--days= : Override the configured retention window}';

    protected $description = 'Permanently delete activities older than the retention window (opt-in via storyfeed.prune.after_days)';

    public function handle(): int
    {
        $days = $this->option('days');

        $result = (new PruneActivities)($days === null ? null : (int) $days);

        if (! $result['enabled']) {
            $this->warn('Pruning is disabled: set storyfeed.prune.after_days (or pass --days).');

            return self::SUCCESS;
        }

        $this->info("Pruned {$result['pruned']} activities.");

        return self::SUCCESS;
    }
}
