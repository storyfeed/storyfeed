<?php

namespace Storyfeed\Commands;

use Illuminate\Console\Command;

class StoryfeedCommand extends Command
{
    public $signature = 'storyfeed';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
