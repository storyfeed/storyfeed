<?php

namespace Storyfeed\Support;

use Illuminate\Contracts\Foundation\Application;
use ReflectionClass;
use SplFileInfo;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Contracts\PublishesToFeed;
use Storyfeed\Story;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Finds the app's declared feed SURFACE: Feedable models, PublishesToFeed
 * implementors, and Story classes.
 *
 * NEVER RUNS AT BOOT. Only commands and tests call this, which is what keeps
 * "explicit registration first, discovery later" intact — nothing behavioural
 * depends on the scan, so it cannot become load-bearing by accident.
 *
 * It exists for one job: answering "what could publish to the feed but doesn't?"
 * A `Feedable` model is an explicit statement that the thing belongs in the
 * feed, so a Feedable model with no story and no activity is a genuine
 * contradiction rather than a guess. (Deliberately NOT scanned: plain app
 * events. Every Laravel app has hundreds, a heuristic list of "events that maybe
 * should publish" is pure noise, and a tool people learn to ignore is worse than
 * no tool.)
 *
 * Follows Illuminate\Foundation\Events\DiscoverEvents' pattern — Finder plus
 * Reflection, no new dependency.
 */
class SurfaceScanner
{
    public function __construct(
        protected Application $app,
    ) {}

    /**
     * @return array{feedable: list<class-string>, publishers: list<class-string>, stories: list<class-string>}
     */
    public function scan(): array
    {
        $found = ['feedable' => [], 'publishers' => [], 'stories' => []];

        foreach ($this->paths() as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach (Finder::create()->files()->name('*.php')->in($path) as $file) {
                $class = $this->classFor($file);

                if ($class === null) {
                    continue;
                }

                if (is_a($class, Feedable::class, true)) {
                    $found['feedable'][] = $class;
                }

                if (is_a($class, PublishesToFeed::class, true)) {
                    $found['publishers'][] = $class;
                }

                if (is_a($class, Story::class, true)) {
                    $found['stories'][] = $class;
                }
            }
        }

        return array_map(fn (array $classes) => array_values(array_unique($classes)), $found);
    }

    /** @return array<int, string> */
    protected function paths(): array
    {
        /** @var array<int, string> $configured */
        $configured = config('storyfeed.discovery.paths') ?? [$this->app->path()];

        return $configured;
    }

    /**
     * Resolve a file to a loadable, concrete class name by READING its
     * namespace and class declaration.
     *
     * Deliberately not derived from the path. The obvious implementation —
     * `app/Models/User.php` → `App\Models\User` — encodes one directory
     * convention and silently finds NOTHING anywhere else: a package's own
     * workbench, a `src/` layout, a multi-root PSR-4 map, a module package.
     * And "finds nothing" is the worst possible failure for a tool whose job is
     * reporting what is missing, because an empty report reads as a clean one.
     *
     * Anything unresolvable is skipped — a scan is a best-effort inventory, and
     * a file that fails to autoload is the app's problem to surface, not a
     * reason to abort the report.
     *
     * @return class-string|null
     */
    protected function classFor(SplFileInfo $file): ?string
    {
        $source = @file_get_contents($file->getRealPath());

        if ($source === false) {
            return null;
        }

        $namespace = null;
        $name = null;

        $tokens = token_get_all($source);

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $namespace = $this->readName($tokens, $i);
            }

            // T_CLASS also fires for `Foo::class`; a real declaration is
            // followed by a name token, an anonymous class by `(` or `{`.
            if (in_array($token[0], [T_CLASS, T_ENUM], true)) {
                $candidate = $this->readName($tokens, $i);

                if ($candidate !== null) {
                    $name = $candidate;

                    break;
                }
            }
        }

        if ($name === null) {
            return null;
        }

        $class = $namespace === null ? $name : $namespace.'\\'.$name;

        try {
            if (! class_exists($class)) {
                return null;
            }

            return (new ReflectionClass($class))->isInstantiable() ? $class : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The dotted or bare name following a keyword token.
     *
     * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
     */
    protected function readName(array $tokens, int $from): ?string
    {
        $name = '';

        for ($i = $from + 1; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                if ($name !== '') {
                    break;
                }

                continue;
            }

            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $name .= $token[1];

                continue;
            }

            break;
        }

        return $name === '' ? null : ltrim($name, '\\');
    }
}
