<?php

namespace Storyfeed\Sources;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Storyfeed\Contracts\FeedSource;
use Storyfeed\Contracts\StaticItem;
use Symfony\Component\Finder\Finder;

/**
 * Static content as one class per item, listed or discovered from a directory
 * as Pennant discovers feature classes: a file's class name is the namespace
 * followed by its path relative to the directory.
 *
 *   // config/storyfeed.php
 *   'sources' => [
 *       'changelog' => ['driver' => 'classes', 'path' => app_path('Feed/Changelog'), 'namespace' => 'App\Feed\Changelog'],
 *       'roadmap' => ['driver' => 'classes', 'items' => [Now::class, Next::class]],
 *   ],
 *
 * Without a `namespace`, the app namespace plus the path under app_path(), as
 * Pennant assumes. Each class implements {@see StaticItem} and is resolved from
 * the container when the source is read, so it may take dependencies. Files
 * that are not StaticItem classes are skipped. `in_order` is as on ArraySource.
 */
final class ClassSource implements FeedSource
{
    /**
     * @param  list<class-string<StaticItem>>  $classes  listed explicitly, before any discovered
     */
    public function __construct(
        protected Container $container,
        protected array $classes = [],
        protected ?string $path = null,
        protected ?string $namespace = null,
        public readonly bool $inOrder = false,
    ) {}

    public function items(): iterable
    {
        foreach ($this->classNames() as $class) {
            if (! is_a($class, StaticItem::class, true)) {
                throw new InvalidArgumentException("[{$class}] must implement ".StaticItem::class.'.');
            }

            yield $this->container->make($class)->entry();
        }
    }

    /** @return list<class-string> */
    protected function classNames(): array
    {
        $classes = $this->classes;

        if ($this->path !== null && is_dir($this->path)) {
            $path = realpath($this->path);
            $namespace = rtrim($this->namespace ?? $this->defaultNamespace($path), '\\');

            foreach (Finder::create()->files()->name('*.php')->in($path)->sortByName() as $file) {
                $relative = substr($file->getRealPath(), strlen($path) + 1, -4);
                $class = $namespace.'\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);

                if (class_exists($class) && is_a($class, StaticItem::class, true)
                    && (new \ReflectionClass($class))->isInstantiable()) {
                    $classes[] = $class;
                }
            }
        }

        return array_values(array_unique($classes));
    }

    protected function defaultNamespace(string $path): string
    {
        $app = realpath($this->container->path()) ?: $this->container->path();
        $under = str_starts_with($path, $app) ? trim(substr($path, strlen($app)), DIRECTORY_SEPARATOR) : '';

        return $this->container->getNamespace().str_replace(DIRECTORY_SEPARATOR, '\\', $under);
    }
}
