<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Healing\HealFeed;
use Storyfeed\Healing\HealOutcome;
use Symfony\Component\Console\Formatter\OutputFormatter;

class HealCommand extends Command
{
    protected $signature = 'storyfeed:heal
        {--pretend : Preview permanent-source retirements without writing rows}
        {--dry-run : Deprecated: use --pretend}
        {--only=* : Run only the named healers}';

    protected $description = 'Retire stories for permanently absent sources; writes bump sync_token and require clients to resync';

    public function handle(HealFeed $healing): int
    {
        $pretend = (bool) $this->option('pretend');
        $only = $this->option('only');

        // Symfony can't hide an option, so --dry-run stays listed, labelled.
        if ($this->option('dry-run')) {
            $this->warn('--dry-run is deprecated and will be removed before v1; use --pretend.');
            $pretend = true;
        }

        $this->warn($pretend
            ? 'Preview only. Applying retirements rewrites history and bumps sync_token; accumulating clients must resync.'
            : 'Healing rewrites history and bumps sync_token; accumulating clients must resync. Prefer a quiet period and preview with --pretend.');

        $retired = 0;
        $unchanged = 0;

        foreach ($healing->run($pretend, $only === [] ? null : $only) as $result) {
            $result->outcome === HealOutcome::Retired ? $retired++ : $unchanged++;
            $meta = $result->candidate->meta === [] ? '' : json_encode($result->candidate->meta, JSON_THROW_ON_ERROR);

            $this->line(OutputFormatter::escape(implode('  ', [
                $result->healer,
                $result->candidate->label,
                $result->outcome->value,
                $meta,
            ])));
        }

        $this->info(($pretend ? 'Would retire: ' : 'Retired: ')."{$retired}; unchanged: {$unchanged}.");

        return self::SUCCESS;
    }
}
