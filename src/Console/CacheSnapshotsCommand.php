<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;

/**
 * The bounded snapshot pass, as `php artisan optimize` runs it.
 *
 * WHY THIS EXISTS RATHER THAN A REGISTRATION CARRYING AN OPTION.
 * `ServiceProvider::optimizes()` takes a command NAME, and the optimize
 * command runs it through `callSilently()`, which resolves a name through
 * Symfony's `find()`. A registration of `'storyfeed:rebuild --recent=1000'`
 * therefore asks Symfony to find a command literally called that, and every
 * `php artisan optimize` — which is to say every deploy — fails on it.
 *
 * It shipped that way on 2026-09-11 and broke the first consumer to update
 * past it. The registration was tested; running it never was.
 *
 * So the option lives here, where a name is all the hook needs.
 */
class CacheSnapshotsCommand extends Command
{
    protected $signature = 'storyfeed:cache-snapshots';

    protected $description = 'Recompile the newest entity snapshots, as a deploy compiles assets';

    protected $hidden = true;

    public function handle(): int
    {
        // By class with a parameter array: the branch of `resolveCommand()`
        // that does not go through name lookup, so the bound arrives as an
        // option rather than as part of a name.
        return $this->call(RebuildCommand::class, ['--recent' => RebuildCommand::RECENT]);
    }
}
