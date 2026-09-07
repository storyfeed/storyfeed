<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\MaintenanceHistory;

class Maintenance extends Check
{
    public function name(): string
    {
        return 'maintenance';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        foreach (['curate', 'trickle'] as $command) {
            $runs = MaintenanceHistory::recent($command);

            if ($runs === []) {
                yield Finding::info('maintenance.empty', "No retained completed {$command} passes; no history has been reconstructed.", ['command' => $command]);
            }

            foreach ($runs as $run) {
                $counts = collect($run)->except('at')->map(fn ($value, $key) => "{$key}={$value}")->implode(', ');

                yield Finding::info('maintenance.'.$command, "{$command} at {$run['at']}: {$counts}.", $run);
            }
        }
    }
}
