<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Support\StoryManifest;

/**
 * Remove the compiled-story manifest. Registered with `optimize:clear`.
 */
class ClearCommand extends Command
{
    protected $signature = 'storyfeed:clear';

    protected $description = 'Remove the cached story manifest';

    public function handle(StoryManifest $manifest): int
    {
        if ($manifest->delete()) {
            $this->info('Cached story manifest removed.');

            return self::SUCCESS;
        }

        $this->line('No cached story manifest to remove.');

        return self::SUCCESS;
    }
}
