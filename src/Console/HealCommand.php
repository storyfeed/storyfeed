<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Healing\HealFeed;
use Storyfeed\Healing\HealOutcome;
use Symfony\Component\Console\Formatter\OutputFormatter;

class HealCommand extends Command
{
    protected $signature = 'storyfeed:heal
        {--dry-run : Preview permanent-source retirements without writing rows}
        {--only=* : Run only the named healers}';

    protected $description = 'Retire stories for permanently absent sources; writes bump sync_token and require clients to resync';

    public function handle(HealFeed $healing): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('only');

        $this->warn($dryRun
            ? 'Dry run: preview only. Applying retirements rewrites history and bumps sync_token; accumulating clients must resync.'
            : 'Healing rewrites history and bumps sync_token; accumulating clients must resync. Prefer a quiet period and preview with --dry-run.');

        $retired = 0;
        $unchanged = 0;

        foreach ($healing->run($dryRun, $only === [] ? null : $only) as $result) {
            $result->outcome === HealOutcome::Retired ? $retired++ : $unchanged++;
            $meta = $result->candidate->meta === [] ? '' : json_encode($result->candidate->meta, JSON_THROW_ON_ERROR);

            $this->line(OutputFormatter::escape(implode('  ', [
                $result->healer,
                $result->candidate->label,
                $result->outcome->value,
                $meta,
            ])));
        }

        $this->info(($dryRun ? 'Would retire: ' : 'Retired: ')."{$retired}; unchanged: {$unchanged}.");

        return self::SUCCESS;
    }
}
