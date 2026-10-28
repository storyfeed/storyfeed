<?php

namespace Storyfeed\Listeners;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Support\Facades\Log;

/**
 * Compile the newest snapshots once `migrate` has finished.
 *
 * WHY HERE AND NOT IN `php artisan optimize`. A host such as Laravel Cloud
 * runs `optimize` in the build step, against the production database and
 * before the deploy step migrates. The pass loads participants through their
 * models, so a release that adds a column and a scope using it (SoftDeletes'
 * `deleted_at`) failed every build until the migration shipped alone (#225).
 *
 * `migrate` is the one step every deploy runs after the schema is current, so
 * the pass follows it with no build step to add. NoPendingMigrations covers a
 * deploy with nothing to migrate. A failure warns and is skipped: a stale
 * snapshot is a smaller bug than a failed deploy, and the trickle catches up.
 */
class WarmSnapshots
{
    public function __construct(protected Kernel $kernel) {}

    public function handle(MigrationsEnded|NoPendingMigrations $event): void
    {
        if ($event->method !== 'up' || ($event instanceof MigrationsEnded && ($event->options['pretend'] ?? false))) {
            return;
        }

        try {
            $this->kernel->call('storyfeed:cache-snapshots');
        } catch (\Throwable $e) {
            Log::warning('Storyfeed skipped compiling snapshots after migrate: '.$e->getMessage());
        }
    }
}
