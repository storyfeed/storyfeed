<?php

namespace Storyfeed\Stories;

use BackedEnum;
use Closure;
use DateInterval;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\StoryMisconfigured;

/**
 * A message class bound to its verb in routes/feed.php, as an invokable
 * controller is bound to its route:
 *
 *     Story::for(Task::class)->verb('complete', TaskWasCompleted::class);
 *
 *     Story::for(Task::class)->group(function () {
 *         Story::verb('complete', TaskWasCompleted::class);
 *     });
 *
 * The line names the verb and the types, so the class may leave `$verb` and
 * `$objectType` out; if it declares them, they must agree with the line.
 * A resource Story class is bound with `Story::resource()` instead.
 *
 * Read when stories compile, like PendingResource, from an instance made
 * without the constructor (see Verb::presentation()): a message class takes
 * its data there, and at boot there is none.
 *
 * The line takes middleware, as a route bound to a controller does, ahead of
 * the class's own `middleware()`:
 *
 *     Story::verb('create', ProjectWasCreated::class)->unbatched();
 *
 * Made by the Story facade.
 */
final class BoundStory
{
    /** @var list<string|Closure> */
    private array $middleware = [];

    /** @var list<string> */
    private array $excludedMiddleware = [];

    /** @var list<array{0: 'batched', 1: string|DateInterval|null}|array{0: 'unbatched'}> in the order called */
    private array $shortcuts = [];

    /**
     * @param  array<int, string>|null  $objectTypes  the scope's types; null outside one, where the class's own stand
     * @param  class-string<Story>  $class
     */
    public function __construct(
        public readonly ?array $objectTypes,
        public readonly string|FeedVerb|BackedEnum $verb,
        public readonly string $class,
        public readonly string $source,
    ) {}

    /**
     * Check the class is a message class, at the line that names it.
     *
     * @param  array<int, string>|null  $objectTypes
     */
    public static function make(?array $objectTypes, string|FeedVerb|BackedEnum $verb, string $class, string $source): self
    {
        if (! is_a($class, Story::class, true)) {
            throw StoryMisconfigured::notAOneVerbStory($source, $class);
        }

        return new self($objectTypes, $verb, $class, $source);
    }

    /**
     * @param  string|list<string|Closure>|Closure  $middleware
     *
     * @see Verb::middleware()
     */
    public function middleware(string|array|Closure $middleware): self
    {
        $this->middleware = [...$this->middleware, ...Verb::middlewareList($middleware, $this->source)];

        return $this;
    }

    /**
     * @param  string|list<string>  $middleware
     *
     * @see Verb::withoutMiddleware()
     */
    public function withoutMiddleware(string|array $middleware): self
    {
        $this->excludedMiddleware = [...$this->excludedMiddleware, ...(is_array($middleware) ? $middleware : [$middleware])];

        return $this;
    }

    /** @see Verb::batched() */
    public function batched(string|DateInterval|null $within = null): self
    {
        // Checked now, at the line, rather than when stories compile.
        if ($within !== null) {
            Verb::for('*', $this->verb, $this->source)->batched($within);
        }

        $this->shortcuts[] = ['batched', $within];

        return $this;
    }

    /** @see Verb::unbatched() */
    public function unbatched(): self
    {
        $this->shortcuts[] = ['unbatched'];

        return $this;
    }

    /** The definition the class compiles to, for the line's verb and types. */
    public function definition(): Verb
    {
        $story = Verb::presentation($this->class);
        $bound = Verb::for('*', $this->verb, $this->source)->verb;

        if ($story->verb !== null && ($declared = Verb::for('*', $story->verb, $this->source)->verb) !== $bound) {
            throw StoryMisconfigured::boundVerbDiffers($this->source, $this->class, $declared, $bound);
        }

        if ($this->objectTypes !== null && $story->objectType !== null) {
            $declared = Verb::for($story->objectType, '*', $this->source)->objectTypes;
            $scope = Verb::for($this->objectTypes, '*', $this->source)->objectTypes;

            if (array_diff($declared, $scope) !== [] || array_diff($scope, $declared) !== []) {
                throw StoryMisconfigured::boundTypeDiffers($this->source, $this->class, $declared, $scope);
            }
        }

        // The class's enum case, when it has one, carries the AS2.0 type.
        $definition = Verb::fromStory($story, $this->objectTypes, $story->verb ?? $this->verb, $this->source);

        // The line's middleware runs ahead of the class's own, as a route's
        // runs ahead of its controller's; its shortcuts are said last.
        $definition->prependMiddleware($this->middleware)->withoutMiddleware($this->excludedMiddleware);

        foreach ($this->shortcuts as $shortcut) {
            $shortcut[0] === 'batched' ? $definition->batched($shortcut[1]) : $definition->unbatched();
        }

        return $definition;
    }
}
