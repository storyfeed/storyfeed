<?php

namespace Storyfeed\Stories;

use Illuminate\Contracts\Foundation\Application;
use Storyfeed\StoryfeedManager;

/**
 * The definitions file: `routes/feed.php`, where the `Story` facade says what
 * each activity reads as, the way `routes/web.php` holds routes.
 *
 * LOADED IN `booted()`, not `boot()`: package providers boot before app
 * providers, so a file required during the package's boot would run before
 * `AppServiceProvider` enforces the morph map, and `Story::for(Order::class)`
 * would key on the class name. `booted()` runs after every provider. It is the
 * timing Laravel gives `routes/channels.php`, with the same `file_exists`
 * guard.
 *
 * SKIPPED WHEN CACHED, like a route file after `route:cache`: the manifest
 * holds what the file compiled to. Anything that needs the definitions
 * themselves (the doctor, `storyfeed:list`, `storyfeed:cache`) loads it on
 * demand through StoryfeedManager::storyDefinitions(); the read path only
 * reads registries, so it never does.
 *
 * THE FILE HOLDS DEFINITIONS ONLY. A `Storyfeed::grammar()` or `axes()` call
 * in it would stop running once cached, so the loader notes which registries
 * the file wrote to, and `storyfeed:cache` refuses to cache while any did.
 */
class DefinitionsFile
{
    protected bool $loaded = false;

    /** @var list<string> hand-written registries the file wrote to */
    protected array $registered = [];

    public function __construct(
        protected Application $app,
    ) {}

    /**
     * The configured path, or null when loading is turned off.
     */
    public function path(): ?string
    {
        $path = config('storyfeed.definitions', $this->app->basePath('routes/feed.php'));

        return is_string($path) && $path !== '' ? $path : null;
    }

    public function exists(): bool
    {
        return ($path = $this->path()) !== null && is_file($path);
    }

    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /**
     * Require the file, once per process. Returns whether it was required
     * by this call.
     */
    public function load(StoryfeedManager $storyfeed): bool
    {
        if ($this->loaded) {
            return false;
        }

        // Set first: a definition in the file may read the definitions back
        // (a helper, a test), and that must not require the file again.
        $this->loaded = true;

        if (! $this->exists()) {
            return false;
        }

        $before = $storyfeed->handWrittenRegistries();

        // An isolated scope, as Laravel's loadRoutesFrom() gives route files.
        (static function (string $path): void {
            require $path;
        })((string) $this->path());

        $after = $storyfeed->handWrittenRegistries();

        $this->registered = array_keys(array_filter(
            $after,
            fn (array $keys, string $registry) => $keys !== ($before[$registry] ?? []),
            ARRAY_FILTER_USE_BOTH,
        ));

        return true;
    }

    /**
     * The hand-written registries (`grammar()`, `axes()`, …) the file wrote
     * to. They run at boot but can't be cached with the file.
     *
     * @return list<string>
     */
    public function handWrittenRegistrations(): array
    {
        return $this->registered;
    }

    /**
     * The path as the app would write it (`routes/feed.php`).
     */
    public function relativePath(): string
    {
        $path = (string) $this->path();
        $base = $this->app->basePath().DIRECTORY_SEPARATOR;

        return str_starts_with($path, $base) ? str_replace('\\', '/', substr($path, strlen($base))) : $path;
    }
}
