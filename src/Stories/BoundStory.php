<?php

namespace Storyfeed\Stories;

use BackedEnum;
use Closure;
use DateInterval;
use ReflectionMethod;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\StoryMisconfigured;

/**
 * A class bound to its verb in routes/feed.php, as a controller is bound to
 * its route. Two shapes bind here, told apart as the router tells an
 * invokable controller from the rest, by the class itself:
 *
 *     Story::for(Task::class)->verb('complete', TaskWasCompleted::class);   // a message class
 *     Story::for(Order::class)->verb('ship', ShipStory::class);             // an invokable class
 *     Story::verb('confirm', ConfirmStory::class);                          // either, for every type
 *
 * A MESSAGE CLASS extends Story and is constructed with its data. The line
 * names the verb and the types, so the class may leave `$verb` and
 * `$objectType` out; if it declares them, they must agree with the line.
 * Read when stories compile, like PendingResource, from an instance made
 * without the constructor (see Verb::presentation()): a message class takes
 * its data there, and at boot there is none.
 *
 * AN INVOKABLE CLASS extends nothing and declares one public method,
 * `__invoke(Verb $verb)`: a resource class's action (see ResourceClass) for
 * exactly one verb, taking and returning what an action does. It is
 * `Class@__invoke` wherever an action is named, as Laravel stores an
 * invokable controller, and nothing constructs it at a call site:
 * `story('ship', $order)` publishes it like any declared verb. Bound
 * outside a group it defines the verb for every type, which is what lets it
 * write the `Group::byActors()` headline a resource class can't.
 *
 * A resource Story class is bound with `Story::resource()` instead.
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
     * @param  array<int, string>|null  $objectTypes  the scope's types; null outside one, where a message class's own stand
     * @param  class-string  $class
     */
    public function __construct(
        public readonly ?array $objectTypes,
        public readonly string|FeedVerb|BackedEnum $verb,
        public readonly string $class,
        public readonly string $source,
        public readonly bool $invokable = false,
    ) {}

    /**
     * Tell the class's shape at the line that names it: a Story subclass is
     * a message, a public `__invoke` is invokable, and a class that is both
     * or neither is an error. The router's `method_exists($action, '__invoke')`.
     *
     * @param  array<int, string>|null  $objectTypes
     */
    public static function make(?array $objectTypes, string|FeedVerb|BackedEnum $verb, string $class, string $source): self
    {
        $message = is_subclass_of($class, Story::class);
        $invokable = method_exists($class, '__invoke') && (new ReflectionMethod($class, '__invoke'))->isPublic();

        if ($message === $invokable) {
            throw $message
                ? StoryMisconfigured::bothStoryShapes($source, $class)
                : StoryMisconfigured::notAOneVerbStory($source, $class);
        }

        return new self($objectTypes, $verb, $class, $source, $invokable);
    }

    /** Whether the class is a message class, constructed and published with `Storyfeed::publish()`. */
    public function isMessage(): bool
    {
        return ! $this->invokable;
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
        $definition = $this->invokable ? $this->invokableDefinition() : $this->messageDefinition();

        foreach ($this->shortcuts as $shortcut) {
            $shortcut[0] === 'batched' ? $definition->batched($shortcut[1]) : $definition->unbatched();
        }

        return $definition;
    }

    /**
     * Run `__invoke` once, as a resource class's action runs, on a definition
     * for the line's types, or every type outside a group.
     */
    private function invokableDefinition(): Verb
    {
        $uses = ResourceClass::uses($this->class, '__invoke');
        $takesRequest = ResourceClass::invokable($this->class)['request'];

        $definition = Verb::for($this->objectTypes ?? ['*'], $this->verb, $this->source)
            ->middleware($this->middleware)
            ->withoutMiddleware($this->excludedMiddleware);

        return ResourceClass::run($this->class, '__invoke', $definition)->fromAction($uses, $takesRequest);
    }

    private function messageDefinition(): Verb
    {
        /** @var class-string<Story> $class */
        $class = $this->class;
        $story = Verb::presentation($class);
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
        return $definition->prependMiddleware($this->middleware)->withoutMiddleware($this->excludedMiddleware);
    }
}
