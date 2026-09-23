<?php

namespace Storyfeed\Support;

use Closure;
use Laravel\SerializableClosure\SerializableClosure;
use Laravel\SerializableClosure\Support\ReflectionClosure;
use ReflectionFunction;
use RuntimeException;
use Throwable;

/**
 * A closure headline in the cached manifest, serialised the way `route:cache`
 * serialises closure routes: `serialize(SerializableClosure::unsigned(…))`.
 *
 * var_export() writes it as `ManifestClosure::__set_state([...])`, and
 * __set_state() hands back a CLOSURE, so the manifest's registries hold
 * closures again the moment it is required and nothing downstream knows it
 * was cached.
 *
 * That closure unserialises the headline on its first call, not at boot, as
 * a cached route's closure is unserialised only when the route matches
 * (`Route::runCallable`). A page pays for the verbs it renders, not the
 * ~21µs per closure that unserialising all of them at boot cost every request.
 *
 * @internal
 */
final class ManifestClosure
{
    private function __construct(
        public readonly string $serialized,
    ) {}

    /**
     * Serialise a closure, proving it comes back. A closure that can't (one
     * bound to a service provider, one that `use`s a connection) throws,
     * naming the line it was written on.
     */
    public static function wrap(Closure $closure, string $entry): self
    {
        try {
            $serialized = serialize(SerializableClosure::unsigned($closure));

            unserialize($serialized)->getClosure();
        } catch (Throwable $e) {
            throw new RuntimeException(
                "The closure for {$entry} (".self::location($closure).") can't be serialised: {$e->getMessage()}. "
                .'Make it a `static fn`, pass only plain values into it, or use a template string or FeedHeadline::trans().',
                previous: $e,
            );
        }

        return new self($serialized);
    }

    /**
     * @param  array{serialized: string}  $state
     */
    public static function __set_state(array $state): Closure
    {
        $serialized = $state['serialized'];
        $closure = null;

        return static function (mixed ...$arguments) use ($serialized, &$closure): mixed {
            $closure ??= self::unserialize($serialized);

            return $closure(...$arguments);
        };
    }

    /**
     * The headline a manifest closure stands in for, unserialised now; any
     * other closure as it is.
     */
    public static function resolve(Closure $closure): Closure
    {
        $reflection = new ReflectionFunction($closure);

        if ($reflection->getClosureScopeClass()?->getName() !== self::class) {
            return $closure;
        }

        $used = $reflection->getClosureUsedVariables();

        return $used['closure'] ?? self::unserialize($used['serialized']);
    }

    private static function unserialize(string $serialized): Closure
    {
        /** @var SerializableClosure $closure */
        $closure = unserialize($serialized);

        return $closure->getClosure();
    }

    /**
     * The closure's source, for comparing a cached closure with a fresh one:
     * a deserialised closure keeps its code, not its file.
     */
    public static function fingerprint(Closure $closure): string
    {
        try {
            return 'closure:'.(new ReflectionClosure(self::resolve($closure)))->getCode();
        } catch (Throwable) {
            return 'closure';
        }
    }

    /** `routes/feed.php:14`, relative to the app. */
    public static function location(Closure $closure): string
    {
        $reflection = new ReflectionFunction(self::resolve($closure));
        $file = (string) $reflection->getFileName();
        $base = app()->basePath().DIRECTORY_SEPARATOR;

        if (str_starts_with($file, $base)) {
            $file = substr($file, strlen($base));
        }

        return str_replace('\\', '/', $file).':'.$reflection->getStartLine();
    }
}
