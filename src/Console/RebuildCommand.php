<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Actions\RebuildSnapshots;

class RebuildCommand extends Command
{
    protected $signature = 'storyfeed:rebuild';

    protected $description = 'Rebuild the snapshot for every entity referenced in the feed and backfill cached links';

    public function handle(): int
    {
        $result = (new RebuildSnapshots)();

        $this->info("Snapshotted {$result['snapshotted']} entities ({$result['missing']} missing).");

        return self::SUCCESS;
    }
}
