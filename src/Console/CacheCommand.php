<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\StoryManifest;

/**
 * Compile registered stories into `bootstrap/cache/storyfeed.php`.
 *
 * Named `storyfeed:cache` / `storyfeed:clear` rather than `:compile` because
 * `config:cache` / `config:clear` is the pattern every Laravel developer can
 * guess without reading anything. Both are registered with the framework's
 * `optimize` and `optimize:clear`, so a deploy that already runs
 * `php artisan optimize` picks this up with no change.
 *
 * The manifest matters less for today's compile cost — O(stories), no I/O —
 * than for what it unblocks: autoload DISCOVERY, whose real expense is
 * scanning the filesystem on every boot. Building the cache now is what makes
 * discovery a scanner plus a cache entry later, rather than a performance
 * problem.
 */
class CacheCommand extends Command
{
    protected $signature = 'storyfeed:cache';

    protected $description = 'Compile registered stories into a cached manifest';

    public function handle(StoryfeedManager $storyfeed, StoryManifest $manifest): int
    {
        // Clear first: a stale manifest must never be what a failed compile
        // leaves behind, and compiledStories() below reads the registered
        // stories, not the cache.
        $manifest->delete();

        if ($storyfeed->registeredStories() === []) {
            $this->warn('No stories are registered, so there is nothing to cache.');
            $this->line('Register them with Storyfeed::stories([...]) in a service provider.');

            return self::SUCCESS;
        }

        try {
            $compiled = $storyfeed->compiledStories();
        } catch (StoryMisconfigured $e) {
            // Writing nothing is the whole point: a broken Story must not be
            // able to leave a half-manifest that boots.
            $this->error('Stories failed to compile — nothing was cached.');
            $this->newLine();
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        $path = $manifest->write($compiled);

        $count = count($storyfeed->registeredStories());
        $keys = count($compiled['grammar']) + count($compiled['aggregateGrammar']) + count($compiled['icons']);

        $this->info("Cached {$count} stories ({$keys} registry entries) to {$path}.");

        // Say it plainly. The failure mode is editing a Story and forgetting.
        $this->line('Re-run this after changing a Story; storyfeed:doctor reports a stale manifest.');

        return self::SUCCESS;
    }
}
