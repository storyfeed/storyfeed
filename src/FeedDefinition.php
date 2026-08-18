<?php

namespace Storyfeed;

use Closure;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionFunction;
use Storyfeed\Exceptions\FeedMisconfigured;

/**
 * A named feed as data — the normalized form both authoring paths funnel into.
 *
 * Two ways to declare one audience, one registry:
 *
 *   Storyfeed::feeds([
 *       'customer' => CustomerFeed::class,                        // a class
 *       AdminFeed::class,                                         // a class, name derived
 *       'kitchen' => fn (FeedBuilder $feed) => $feed->only(...),  // a closure, ad-hoc
 *   ]);
 *
 * The closure form is the StoryDefinition analogue and stays first-class: a
 * two-line admin preset should not need a file. Because the manager and doctor
 * consume only this type, the forms are provably the same thing — a test
 * asserts a class feed and its equivalent closure produce identical queries.
 *
 * WHERE THE STORY ANALOGY STOPS. A StoryDefinition compiles to DATA, which is
 * what lets `storyfeed:cache` var_export the registries and skip compilation
 * altogether. A feed compiles to BEHAVIOUR — define() may call query() with a
 * closure, scope() binds a live model — so it can never enter the manifest.
 * That is not a shortfall: feeds are registered, not compiled, so there is
 * nothing to cache and `storyfeed:cache` is a no-op for them by construction.
 *
 * THE SECOND PLACE IT STOPS: a Story can always be instantiated, and a Feed
 * cannot. A subject feed takes its subject as a constructor argument, so
 * tooling holding only a class-string cannot build one. That is why a Feed has
 * two hooks: define() is readable without a subject (doctor uses an instance
 * built WITHOUT the constructor), scope() is not.
 */
final class FeedDefinition
{
    /** The roles a subject may be bound to in a Feed's scope(). */
    public const ROLES = ['involving', 'context', 'target', 'actor', 'object'];

    /**
     * @param  Closure(FeedBuilder): mixed|null  $preset  the closure form
     * @param  Feed|null  $instance  the class form
     * @param  bool  $constructed  false when $instance was built for INSPECTION
     *                             only, bypassing a constructor it could not
     *                             satisfy — such a definition can be read but
     *                             never run
     */
    private function __construct(
        public readonly string $name,
        public readonly ?string $feedClass,
        public readonly string $source,
        private readonly ?Closure $preset,
        private readonly ?Feed $instance,
        private readonly bool $constructed,
        private readonly bool $needsArguments,
    ) {}

    /**
     * Normalize one `feeds()` entry. A string key names it; an integer key
     * (a bare list entry) means the class names itself.
     */
    public static function normalize(int|string $key, Closure|Feed|string $value): self
    {
        if ($value instanceof Closure) {
            if (! is_string($key)) {
                throw new InvalidArgumentException(
                    'A closure feed preset needs a name: Storyfeed::feeds([\'customer\' => fn ($feed) => ...]). '
                    .'Only Feed classes may be registered without one, because they carry their own.'
                );
            }

            return self::fromClosure($key, $value);
        }

        return self::fromFeed($value, is_string($key) ? $key : null);
    }

    public static function fromClosure(string $name, Closure $preset): self
    {
        return new self(
            name: $name,
            feedClass: null,
            source: self::locate(...self::reflectFunction($preset)),
            preset: $preset,
            instance: null,
            constructed: true,
            needsArguments: false,
        );
    }

    /**
     * From a Feed instance (live, from `make()`) or a class-string (inspection
     * only — a class with required constructor arguments is instantiated
     * WITHOUT them, which is safe precisely because define() is contractually
     * forbidden from reading constructor state).
     */
    public static function fromFeed(Feed|string $feed, ?string $name = null): self
    {
        if (is_string($feed)) {
            if (! class_exists($feed) || ! is_a($feed, Feed::class, true)) {
                throw FeedMisconfigured::notAFeed($feed);
            }

            $needsArguments = self::needsArguments($feed);
            $instance = $needsArguments
                ? (new ReflectionClass($feed))->newInstanceWithoutConstructor()
                : new $feed;
            $constructed = ! $needsArguments;
        } else {
            $instance = $feed;
            $needsArguments = self::needsArguments($feed::class);
            $constructed = true;
        }

        /** @var Feed $instance */
        return new self(
            name: $name ?? $instance::name(),
            feedClass: $instance::class,
            source: self::locate(...self::reflectClass($instance::class)),
            preset: null,
            instance: $instance,
            constructed: $constructed,
            needsArguments: $needsArguments,
        );
    }

    /** `CustomerFeed` → `customer`; `KitchenWallFeed` → `kitchen-wall`. */
    public static function deriveName(string $class): string
    {
        $base = class_basename($class);

        if ($base !== 'Feed' && str_ends_with($base, 'Feed')) {
            $base = substr($base, 0, -4);
        }

        return Str::kebab($base);
    }

    /** Whether this feed can be built from its name alone. */
    public function isConstructable(): bool
    {
        return ! $this->needsArguments;
    }

    /** What findings call this feed: the class if there is one, else the name. */
    public function label(): string
    {
        return $this->feedClass ?? $this->name;
    }

    /**
     * The feed's builder: declared, scoped, and locked.
     *
     * A subject feed reached by NAME rather than through its constructor
     * throws. Handing back a builder missing the scope the class was written to
     * carry is the fail-open case this whole layer exists to remove, so there
     * is no unscoped path in — not a discouraged one, not a documented-as-
     * dangerous one.
     */
    public function build(): FeedBuilder
    {
        $feed = new FeedBuilder;

        if ($this->instance === null) {
            /** @var Closure $preset */
            $preset = $this->preset;

            return self::applyPreset($preset, $feed, $this->name);
        }

        if (! $this->constructed) {
            throw FeedMisconfigured::requiresArguments($this->label(), $this->name);
        }

        $this->instance->define($feed);

        $before = count($feed->boundRoles());
        $this->instance->bindScope($feed);
        $bound = array_values(array_unique(array_slice($feed->boundRoles(), $before)));

        // A feed handed a subject that never reaches the query is the exact
        // failure this layer exists to prevent, wearing the layer's own
        // clothes: it LOOKS scoped at every call site.
        if ($bound === [] && $this->needsArguments) {
            throw FeedMisconfigured::unscoped($this->label());
        }

        foreach ($bound as $role) {
            $feed->lockScope($role, $this->label());
        }

        return $feed;
    }

    /**
     * What the feed DECLARES, with nothing bound.
     *
     * @internal Doctor's read-back seam: FeedCoverage asks every feed which
     * verbs it named, including subject feeds it cannot construct.
     */
    public function inspect(): FeedBuilder
    {
        $feed = new FeedBuilder;

        if ($this->instance === null) {
            /** @var Closure $preset */
            $preset = $this->preset;

            return self::applyPreset($preset, $feed, $this->name);
        }

        $this->instance->define($feed);

        return $feed;
    }

    /**
     * The closure form's return-value rule, unchanged from the day it shipped:
     * both `fn ($feed) => $feed->only([...])` and a multi-line closure that
     * forgets to return are accepted — the builder is mutable, so it is the
     * same object either way. Returning anything ELSE is an error, because
     * honouring it would quietly discard the preset.
     */
    private static function applyPreset(Closure $preset, FeedBuilder $feed, string $name): FeedBuilder
    {
        $returned = $preset($feed);

        if ($returned === null) {
            return $feed;
        }

        if (! $returned instanceof FeedBuilder) {
            throw new InvalidArgumentException(
                "Feed [{$name}] returned ".get_debug_type($returned).' instead of a FeedBuilder. '
                .'A preset closure receives the builder and should return it (or nothing) — '
                .'returning something else would silently discard the preset.',
            );
        }

        return $returned;
    }

    /** @param class-string<Feed> $class */
    private static function needsArguments(string $class): bool
    {
        return ((new ReflectionClass($class))->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0;
    }

    /**
     * `app/Feeds/CustomerFeed.php:14` — what a finding points at.
     *
     * Closures reflect too, so the closure form gets this as well: a preset
     * naming a typo'd verb should jump to the provider line that named it. What
     * the class form adds is a STABLE identity — the file IS the feed, and its
     * line does not shift when someone reorders the array in a provider.
     */
    private static function locate(string|false $file, int $line): string
    {
        if ($file === false) {
            return 'an unknown location';
        }

        $base = self::basePath();

        if ($base !== null && str_starts_with($file, $base.DIRECTORY_SEPARATOR)) {
            $file = substr($file, strlen($base) + 1);
        }

        return $file.':'.$line;
    }

    /** @return array{0: string|false, 1: int} */
    private static function reflectFunction(Closure $closure): array
    {
        $reflector = new ReflectionFunction($closure);

        return [$reflector->getFileName(), $reflector->getStartLine()];
    }

    /**
     * @param  class-string  $class
     * @return array{0: string|false, 1: int}
     */
    private static function reflectClass(string $class): array
    {
        $reflector = new ReflectionClass($class);

        return [$reflector->getFileName(), $reflector->getStartLine()];
    }

    private static function basePath(): ?string
    {
        try {
            return app()->basePath();
        } catch (\Throwable) {
            return null;
        }
    }
}
