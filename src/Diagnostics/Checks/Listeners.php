<?php

namespace Storyfeed\Diagnostics\Checks;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use ReflectionFunction;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Events\ActivityDeleted;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Events\BatchClosed;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\SurfaceScanner;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Storyfeed is a side effect, never the source.
 *
 *  - WARNING `listeners.business`: an app listener on ActivityPublished, ActivityDeleted or
 *    BatchClosed. Those events fire only when a feed row is written, so
 *    they do not fire while recording is off, under Storyfeed::fake(), or for
 *    an idempotent duplicate. Logic that must run whenever the thing happens
 *    belongs on the app's own domain event. A listener that only presents the
 *    feed (a cache bust, a live broadcast) is fine; the warning says so.
 *  - INFO `listeners.inline`: many files publish inline and none of the app's
 *    classes implement PublishesToFeed. A source scan, so it only informs.
 */
class Listeners extends Check
{
    /** Files that publish inline before the absence of events is worth a line. */
    public const INLINE_THRESHOLD = 10;

    private const INLINE = '/Storyfeed::(?:activity|record)\s*\(/';

    public function __construct(
        protected SurfaceScanner $scanner,
        protected Dispatcher $events,
    ) {}

    public function name(): string
    {
        return 'listeners';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        foreach ([ActivityPublished::class, ActivityDeleted::class, BatchClosed::class] as $event) {
            foreach ($this->appListeners($event) as $listener) {
                yield Finding::warning(
                    'listeners.business',
                    "{$listener} listens to ".class_basename($event).'. Storyfeed is a side effect, never the source: '
                    .'that event fires only when a feed row is written, so not while recording is off, under '
                    .'Storyfeed::fake(), or for an idempotent duplicate. Move business logic (access, billing, '
                    .'subscriptions, mail, notification routing) to a listener on your own domain event, and publish '
                    .'to the feed from that event with PublishesToFeed. Keep this listener only if it presents the feed.',
                    ['event' => $event, 'listener' => $listener],
                );
            }
        }

        $inline = $this->inlineFiles();

        if ($inline >= self::INLINE_THRESHOLD && $this->scanner->scan()['publishers'] === []) {
            yield Finding::info(
                'listeners.inline',
                "{$inline} files publish inline with Storyfeed::activity() or Storyfeed::record(), and no class "
                .'implements PublishesToFeed. Dispatch your own domain events where things happen and return the '
                .'activity from toFeedActivity() (php artisan make:feed-event); keep inline publishing for imports, '
                .'backfills and commands.',
                ['files' => $inline],
            );
        }
    }

    /**
     * The app's listeners on one event, named; Storyfeed's own are skipped.
     *
     * @return list<string>
     */
    protected function appListeners(string $event): array
    {
        $raw = method_exists($this->events, 'getRawListeners') ? ($this->events->getRawListeners()[$event] ?? []) : [];

        $names = [];

        foreach ($raw as $listener) {
            $name = $this->describe($listener);

            if ($name !== null && ! str_starts_with(ltrim($name, '\\'), 'Storyfeed\\')) {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    protected function describe(mixed $listener): ?string
    {
        if (is_string($listener)) {
            return $listener;
        }

        if (is_array($listener) && isset($listener[0])) {
            return (is_object($listener[0]) ? $listener[0]::class : (string) $listener[0])
                .(isset($listener[1]) ? '@'.$listener[1] : '');
        }

        if ($listener instanceof Closure) {
            try {
                $reflection = new ReflectionFunction($listener);
                $scope = $reflection->getClosureScopeClass()?->getName();
                $file = (string) $reflection->getFileName();

                if (str_starts_with($file, dirname(__DIR__, 2).DIRECTORY_SEPARATOR)) {
                    return null;
                }

                return $scope !== null && ! str_starts_with($scope, 'Illuminate\\')
                    ? "A closure in {$scope}"
                    : 'A closure in '.basename($file).':'.$reflection->getStartLine();
            } catch (Throwable) {
                return 'A closure';
            }
        }

        return is_object($listener) ? $listener::class : null;
    }

    protected function inlineFiles(): int
    {
        /** @var array<int, string> $paths */
        $paths = config('storyfeed.discovery.paths') ?? [app_path()];
        $count = 0;

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach (Finder::create()->files()->name('*.php')->in($path) as $file) {
                if (preg_match(self::INLINE, (string) @file_get_contents($file->getRealPath())) === 1) {
                    $count++;
                }
            }
        }

        return $count;
    }
}
