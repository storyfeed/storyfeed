<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Actions\TrickleSnapshots;

class TrickleCommand extends Command
{
    protected $signature = 'storyfeed:trickle {--limit= : Maximum activities to process this run}';

    protected $description = 'Snapshot uncached feed activities (newest first) and prune orphans';

    public function handle(): int
    {
        $limit = $this->option('limit');

        $result = (new TrickleSnapshots)($limit === null ? null : (int) $limit);

        $this->info(
            "Snapshotted {$result['snapshotted']} activities, pruned {$result['pruned']} orphans, "
            ."reshaped {$result['reshaped']} stale snapshots."
        );

        return self::SUCCESS;
    }
}
