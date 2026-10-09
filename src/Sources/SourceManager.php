<?php

namespace Storyfeed\Sources;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Storyfeed\Contracts\FeedSource;

/**
 * Named sources from `storyfeed.sources`, resolved the way the filesystem
 * manager resolves disks: by driver, built-in or registered with extend(),
 * and kept once built.
 */
class SourceManager
{
    /** @var array<string, FeedSource> */
    protected array $sources = [];

    /** @var array<string, Closure(Application, array<string, mixed>): mixed> */
    protected array $customCreators = [];

    public function __construct(protected Application $app) {}

    /** A named source; the default, `database`, when no name is given. */
    public function source(?string $name = null): FeedSource
    {
        $name ??= $this->getDefaultSource();

        return $this->sources[$name] ??= $this->resolve($name);
    }

    public function getDefaultSource(): string
    {
        return 'database';
    }

    /**
     * Register a driver.
     *
     * @param  Closure(Application, array<string, mixed>): FeedSource  $callback
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback;

        return $this;
    }

    /**
     * Forget built sources, so the next read builds them from config again.
     *
     * @param  list<string>|string  $names
     */
    public function forgetSource(array|string $names): static
    {
        foreach ((array) $names as $name) {
            unset($this->sources[$name]);
        }

        return $this;
    }

    /** @param  array<string, mixed>|null  $config */
    protected function resolve(string $name, ?array $config = null): FeedSource
    {
        $config ??= $this->getConfig($name);

        if (empty($config['driver'])) {
            throw new InvalidArgumentException("Source [{$name}] does not have a configured driver.");
        }

        $driver = $config['driver'];

        if (isset($this->customCreators[$driver])) {
            return $this->callCustomCreator($config);
        }

        $method = 'create'.ucfirst($driver).'Driver';

        if (! method_exists($this, $method)) {
            throw new InvalidArgumentException("Driver [{$driver}] is not supported.");
        }

        return $this->{$method}($config);
    }

    /** @param  array<string, mixed>  $config */
    protected function callCustomCreator(array $config): FeedSource
    {
        $source = $this->customCreators[$config['driver']]($this->app, $config);

        if (! $source instanceof FeedSource) {
            throw new InvalidArgumentException(sprintf(
                'Driver [%s] must return a %s, %s returned.', $config['driver'], FeedSource::class, get_debug_type($source),
            ));
        }

        return $source;
    }

    /** @param  array<string, mixed>  $config */
    public function createDatabaseDriver(array $config): DatabaseSource
    {
        return new DatabaseSource;
    }

    /** @param  array<string, mixed>  $config */
    public function createArrayDriver(array $config): ArraySource
    {
        return new ArraySource($config['items'] ?? []);
    }

    /** @return array<string, mixed> */
    protected function getConfig(string $name): array
    {
        $config = $this->app['config']["storyfeed.sources.{$name}"] ?? null;

        // The default needs no entry: a config published before sources
        // existed still reads the database.
        if ($config === null && $name === $this->getDefaultSource()) {
            return ['driver' => 'database'];
        }

        return $config ?: [];
    }
}
