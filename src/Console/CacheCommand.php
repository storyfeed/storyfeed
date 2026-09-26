<?php

namespace Storyfeed\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use RuntimeException;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Stories\BoundStory;
use Storyfeed\Stories\CompileStories;
use Storyfeed\Stories\DefinitionsFile;
use Storyfeed\Stories\Story;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\SurfaceScanner;

/**
 * Compile registered stories into `bootstrap/cache/storyfeed.php`.
 *
 * Named `storyfeed:cache` / `storyfeed:clear` rather than `:compile` because
 * `config:cache` / `config:clear` is the pattern every Laravel developer can
 * guess without reading anything. Both are registered with the framework's
 * `optimize` and `optimize:clear`, so a deploy that already runs
 * `php artisan optimize` picks this up with no change.
 *
 * THE DEFINITIONS FILE GETS `route:cache` SEMANTICS. Once cached,
 * `routes/feed.php` isn't loaded at boot: the manifest holds what it compiled
 * to, closure headlines included (serialised as closure routes are). So the
 * file may hold definitions only; a `Storyfeed::grammar()` call in it would
 * stop running, and this command refuses rather than drop it. Definitions in
 * a service provider still run every boot; only their output is cached.
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

    protected $description = 'Compile registered stories and routes/feed.php into a cached manifest';

    public function handle(StoryfeedManager $storyfeed, StoryManifest $manifest, DefinitionsFile $file): int
    {
        // Clear first: a stale manifest must never be what a failed compile
        // leaves behind, and compiledStories() below reads the registered
        // stories, not the cache.
        $manifest->delete();

        // Skipped at boot if a manifest existed when this process started.
        $file->load($storyfeed);

        $feedables = app(Feedables::class);

        if (Relation::requiresMorphMap() || $feedables->requiresMorphMap()) {
            $models = array_unique([...app(SurfaceScanner::class)->scan()['feedable'], ...$feedables->registered()]);
            $unaliased = array_filter($models, fn (string $model) => is_a($model, Model::class, true)
                && $feedables->morphAlias(new $model) === null);

            if ($unaliased !== []) {
                $this->error('Feedable morph aliases are required — nothing was cached.');
                $this->line('Add aliases in Relation::morphMap([...]) for: '.implode(', ', $unaliased));

                return self::FAILURE;
            }
        }

        if (($written = $file->handWrittenRegistrations()) !== []) {
            $this->error("{$file->relativePath()} can't be cached — nothing was cached.");
            $this->newLine();
            $this->line('It calls '.implode(', ', array_map(fn (string $registry) => "Storyfeed::{$registry}()", $written))
                .', which would stop running once the file is cached. Move those calls to a service provider\'s boot(), '
                .'or define them with the Story facade.');

            return self::FAILURE;
        }

        try {
            $definitions = $storyfeed->storyDefinitions();
            $compiled = $storyfeed->compiledStories();

            // A duplicate name passes at runtime, where the last one wins,
            // and fails here, as route:cache refuses one.
            (new CompileStories)->assertNamesCacheable($definitions);
        } catch (StoryMisconfigured $e) {
            // Writing nothing is the whole point: a broken Story must not be
            // able to leave a half-manifest that boots.
            $this->error('Stories failed to compile — nothing was cached.');
            $this->newLine();
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        if ($definitions === []) {
            $this->warn('No stories are registered, so there is nothing to cache.');
            $this->line('Define them in routes/feed.php with the Story facade (php artisan storyfeed:install creates it).');

            return self::SUCCESS;
        }

        $classes = array_values(array_unique(array_map(
            fn (BoundStory $story) => $story->class,
            array_filter($storyfeed->registeredStories(), fn (mixed $story) => $story instanceof BoundStory && $story->isMessage()),
        )));

        try {
            $path = $manifest->write($compiled, $classes);
        } catch (RuntimeException $e) {
            // A closure that can't be serialised, named by its line. Nothing
            // was written: the export is built before the file is opened.
            $this->error('A closure headline can\'t be cached — nothing was cached.');
            $this->newLine();
            $this->line($e->getMessage());

            return self::FAILURE;
        }

        $count = count($definitions);
        $keys = count($compiled['grammar']) + count($compiled['aggregateGrammar']) + count($compiled['actorlessGrammar'])
            + count($compiled['icons']) + count($compiled['glyphIntents']) + count($compiled['nouns']) + count($compiled['objectTypes']);

        $this->info("Cached {$count} definitions ({$keys} registry entries) to {$path}.");

        // Say it plainly. The failure mode is editing a definition and forgetting.
        $this->line($file->exists()
            ? "{$file->relativePath()} is no longer loaded at boot. Re-run this after changing it or a Story; storyfeed:doctor reports a stale manifest."
            : 'Re-run this after changing a Story; storyfeed:doctor reports a stale manifest.');

        return self::SUCCESS;
    }
}
