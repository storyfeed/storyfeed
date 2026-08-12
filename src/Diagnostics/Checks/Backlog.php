<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

class Backlog extends Check
{
    public function name(): string
    {
        return 'backlog';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities')) {
            return;
        }

        $backlog = $this->activities()->uncached()->count();

        if ($backlog > 0) {
            yield Finding::warning(
                'backlog.uncached',
                "{$backlog} activities have uncached entities — schedule storyfeed:trickle (or run storyfeed:rebuild).",
                ['count' => $backlog],
            );
        }
    }
}
