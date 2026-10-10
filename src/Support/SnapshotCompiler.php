<?php

namespace Storyfeed\Support;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Testing\ParallelTesting;
use InvalidArgumentException;
use ReflectionClass;
use Storyfeed\Stories\BoundStory;
use Storyfeed\Stories\PendingResource;
use Storyfeed\StoryfeedManager;

/**
 * `storyfeed.snapshots.compile`: when `toFeed()` output is recompiled.
 *
 * `cached` leaves it to `php artisan optimize`. `sync` follows Blade's
 * compiled views rather than hydrating live: the first feed read of a request
 * compares the modification times of the Feedable model and Story class files
 * with the fingerprint stored after the last pass, and when they differ runs
 * the bounded `storyfeed:cache-snapshots` pass once. Reads then use cached
 * snapshots as they do in production, so there is no per-row work and no N+1.
 *
 * Scoped, so a long-running worker checks again on its next request.
 */
class SnapshotCompiler
{
    public const array MODES = ['sync', 'cached'];

    protected bool $checked = false;

    public function __construct(
        protected Application $app,
        protected StoryfeedManager $storyfeed,
        protected Feedables $feedables,
    ) {}

    public function mode(): string
    {
        $mode = config('storyfeed.snapshots.compile') ?? 'cached';

        if (! is_string($mode) || ! in_array($mode, self::MODES, true)) {
            throw new InvalidArgumentException(
                'Snapshot compile mode ['.(is_scalar($mode) ? $mode : get_debug_type($mode)).'] is not supported. '
                .'Set STORYFEED_SNAPSHOTS to sync or cached.',
            );
        }

        return $mode;
    }

    /** In sync mode, recompile once per request when a source file changed. */
    public function compileIfChanged(): void
    {
        if ($this->checked || $this->mode() !== 'sync') {
            return;
        }

        $this->checked = true;
        $fingerprint = $this->fingerprint();
        $path = $this->path();

        if (is_file($path) && file_get_contents($path) === $fingerprint) {
            return;
        }

        $this->app->make(Kernel::class)->call('storyfeed:cache-snapshots');

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, $fingerprint);
    }

    public function fingerprint(): string
    {
        clearstatcache();

        $files = [];
        foreach ($this->files() as $file) {
            $files[$file] = (int) @filemtime($file);
        }
        ksort($files);

        return sha1((string) json_encode($files));
    }

    /**
     * Each Feedable model and Story class file, with its parents and traits,
     * where `toFeed()` may live.
     *
     * @return list<string>
     */
    public function files(): array
    {
        $classes = $this->feedables->registered();

        foreach (Relation::morphMap() as $class) {
            if (class_exists($class) && $this->feedables->isFeedable($class)) {
                $classes[] = $class;
            }
        }

        foreach ($this->storyfeed->registeredStories() as $story) {
            $class = match (true) {
                $story instanceof BoundStory, $story instanceof PendingResource => $story->class,
                default => null,
            };
            if ($class !== null && class_exists($class)) {
                $classes[] = $class;
            }
        }

        $files = [];
        foreach (array_unique($classes) as $class) {
            $this->collect(new ReflectionClass($class), $files);
        }

        return array_keys($files);
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @param  array<string, true>  $files
     */
    protected function collect(ReflectionClass $class, array &$files): void
    {
        if (($file = $class->getFileName()) !== false) {
            $files[$file] = true;
        }

        foreach ($class->getTraits() as $trait) {
            $this->collect($trait, $files);
        }

        if (($parent = $class->getParentClass()) !== false) {
            $this->collect($parent, $files);
        }
    }

    /** Beside compiled views; one file per parallel test worker. */
    public function path(): string
    {
        $token = $this->app->bound(ParallelTesting::class)
            ? $this->app->make(ParallelTesting::class)->token()
            : false;

        return $this->app->storagePath('framework/'.($token ? "storyfeed-snapshots-{$token}" : 'storyfeed-snapshots'));
    }
}
