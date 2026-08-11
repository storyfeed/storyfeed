<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Actions\CloseBatches;

class CloseBatchesCommand extends Command
{
    protected $signature = 'storyfeed:close-batches {--quiet-minutes= : Override the configured quiet window}';

    protected $description = 'Close activity batches whose quiet window has elapsed and fire BatchClosed';

    public function handle(): int
    {
        $quiet = $this->option('quiet-minutes');

        $closed = (new CloseBatches)($quiet === null ? null : (int) $quiet);

        $this->info("Closed {$closed} batch(es).");

        return self::SUCCESS;
    }
}
