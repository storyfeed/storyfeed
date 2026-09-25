<?php

namespace Storyfeed\Stories;

use Closure;
use InvalidArgumentException;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\FeedNoun;

/**
 * The four lifecycle verbs of one model, defined in a line, as
 * `Route::resource()` defines a controller's actions:
 *
 *     Story::resource(Order::class);                            // create, update, delete, restore
 *     Story::resource(Order::class)->only(['create', 'update']);
 *     Story::resource(Document::class)->except('restore')->noun('document|documents');
 *     Story::resource(Order::class, OrderStory::class);         // the four, plus every action
 *
 * WITH A CLASS, every public method of it is an action, and its verb is the
 * method name snake-cased (see ResourceClass). A conventional verb the class
 * has no method for keeps its default here; one it has a method for is
 * replaced whole. Adding a method is the whole change: nothing else names
 * the verb. `only()` and `except()` name verbs as stored (`confirm_payment`).
 *
 * Each verb gets a headline (`:actor created :object`), an anonymous headline
 * (`:object was created`) and an icon. A group of them reads through the noun
 * registry: with `order|orders` registered, five creations by one person read
 * "Sally created orders" with no group headline written.
 *
 * REGISTERED WHEN CALLED, like the rest of the Story facade, and expanded into
 * definitions when stories compile, so `only()` and `except()` still apply
 * after the call. To say something else for one verb, leave it out here and
 * define it with `Story::for(Order::class)->verb('update')`; defining it in
 * both places is a conflict naming both lines.
 */
final class PendingResource
{
    /** The verbs, and what each says by default. */
    public const VERBS = [
        'create' => [':actor created :object', ':object was created', 'plus'],
        'update' => [':actor updated :object', ':object was updated', 'pencil'],
        'delete' => [':actor deleted :object', ':object was deleted', 'trash'],
        'restore' => [':actor restored :object', ':object was restored', 'rotate-ccw'],
    ];

    /** The verbs whose tombstoned object is expected ("deleted an order"). */
    public const REMOVALS = ['delete', 'restore'];

    /** @var list<array{0: 'only'|'except', 1: list<string>}> applied in order, once the verbs are known */
    private array $filters = [];

    private ?FeedNoun $noun = null;

    /** @var list<string> */
    private array $excludedMiddleware = [];

    /**
     * @param  string|array<int, string>  $objectType  a model class, a morph alias, or a list
     * @param  class-string|null  $class  the resource Story class
     * @param  list<string|Closure>  $middleware  an enclosing `Story::middleware()->group()`'s
     */
    public function __construct(
        public readonly string|array $objectType,
        public readonly string $source,
        public readonly ?string $class = null,
        private array $middleware = [],
    ) {
        if ($class !== null && ! class_exists($class)) {
            throw StoryMisconfigured::notAResourceClass($source, $class);
        }
    }

    /**
     * Define only these verbs.
     *
     * @param  string|array<int, string>  ...$verbs
     */
    public function only(string|array ...$verbs): self
    {
        $this->filters[] = ['only', $this->validate($verbs)];

        return $this;
    }

    /**
     * Define every verb but these.
     *
     * @param  string|array<int, string>  ...$verbs
     */
    public function except(string|array ...$verbs): self
    {
        $this->filters[] = ['except', $this->validate($verbs)];

        return $this;
    }

    /**
     * Middleware for every verb of the resource, ahead of what an action
     * declares, as `Route::resource()->middleware()` does.
     *
     * @param  string|list<string|Closure>|Closure  $middleware
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

    /**
     * The type's noun, `'order|orders'`, which is what lets a group of these
     * say "5 orders". The same as `Story::for(Order::class)->noun(…)`.
     */
    public function noun(string|FeedNoun $noun): self
    {
        $this->noun = is_string($noun) ? FeedNoun::of($noun) : $noun;

        return $this;
    }

    /**
     * The definitions this resource stands for. With a class, this is where
     * its actions run: once, each with a blank request.
     *
     * @return list<Verb>
     *
     * @internal
     */
    public function definitions(): array
    {
        $actions = $this->class === null ? [] : ResourceClass::actions($this->class);
        $definitions = [];

        foreach ($this->verbs($actions) as $verb) {
            $definition = $this->withMiddleware(Verb::for($this->objectType, $verb, $this->source));

            if (isset($actions[$verb])) {
                /** @var class-string $class */
                $class = $this->class;
                $uses = ResourceClass::uses($class, $actions[$verb]['method']);

                $definitions[] = ResourceClass::run($class, $actions[$verb]['method'], $this->withMiddleware(Verb::for($this->objectType, $verb, $uses)))
                    ->fromAction($uses, $actions[$verb]['request']);

                continue;
            }

            [$headline, $anonymous, $icon] = self::VERBS[$verb];

            $definition->headline($headline)->anonymousHeadline($anonymous)->icon($icon);

            // Delete and restore are removal verbs: the object they name is
            // expected to be a tombstone, so it never makes them redundant.
            // Said explicitly rather than left to their AS2 types, which an
            // app's own verbs() may map differently.
            if (in_array($verb, self::REMOVALS, true)) {
                $definition->missing();
            }

            $definitions[] = $definition;
        }

        if ($this->noun !== null) {
            $definitions[] = Verb::for($this->objectType, '*', $this->source)->noun($this->noun);
        }

        return $definitions;
    }

    private function withMiddleware(Verb $definition): Verb
    {
        return $definition->middleware($this->middleware)->withoutMiddleware($this->excludedMiddleware);
    }

    /**
     * The verbs left once `only()` and `except()` apply: the conventional
     * four, then the class's own, in the order it declares them.
     *
     * @param  array<string, mixed>  $actions
     * @return list<string>
     */
    private function verbs(array $actions): array
    {
        $all = $verbs = array_values(array_unique([...array_keys(self::VERBS), ...array_keys($actions)]));

        foreach ($this->filters as [$filter, $named]) {
            foreach ($named as $verb) {
                if (! in_array($verb, $all, true)) {
                    throw StoryMisconfigured::unknownResourceVerb($this->source, $verb, $all);
                }
            }

            $verbs = array_values(array_filter($verbs, fn (string $verb) => in_array($verb, $named, true) === ($filter === 'only')));
        }

        return $verbs;
    }

    /**
     * Without a class the verbs are known now, so a typo fails at the call.
     * With one, it fails when stories compile, once the actions are read.
     *
     * @param  array<int, string|array<int, string>>  $verbs
     * @return list<string>
     */
    private function validate(array $verbs): array
    {
        $verbs = array_merge(...array_map(fn (string|array $verb) => array_values((array) $verb), $verbs));

        foreach ($verbs as $verb) {
            if ($this->class === null && ! array_key_exists($verb, self::VERBS)) {
                throw new InvalidArgumentException(
                    "Story::resource() has no [{$verb}] verb. It defines ".implode(', ', array_keys(self::VERBS)).'.',
                );
            }
        }

        return $verbs;
    }
}
