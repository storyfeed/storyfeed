<?php

namespace Storyfeed\Stories;

use BackedEnum;
use Closure;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Middleware\Batch;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\MiddlewareNameResolver;

/**
 * The registrar behind the `Story` facade: Route-style definitions of what
 * each activity says.
 *
 *     use App\Models\Order;
 *     use Storyfeed\Facades\Story;
 *
 *     Story::for(Order::class)->group(function () {
 *         Story::verb('place')->headline(':actor placed :object[ with :target]')->icon('shopping-bag');
 *         Story::verb('complete')->headline(':actor completed :object')->icon('receipt');
 *         Story::fallback()->icon('receipt');                // order.*
 *     });
 *
 *     Story::verb('place')->grouped(fn ($group) => $group->repeat(':actor placed :count orders'));   // *.place
 *     Story::fallback()->icon('activity');                   // *.*
 *
 *     Story::resource(Document::class)->except('restore');   // created, updated, deleted
 *     Story::resource(Order::class, OrderStory::class);      // the four, plus each OrderStory action
 *     Story::for(Task::class)->verb('complete', TaskWasCompleted::class);   // a message class
 *     Story::verb('confirm', ConfirmStory::class);                          // an invokable class, every type
 *
 *     Story::for(Order::class)->verb('confirm')->name('checkout.confirm');   // story('checkout.confirm', $order)
 *     Story::name('billing.')->group(fn () => …);                            // billing.…
 *
 * WHAT IT IS. A front door onto {@see Verb}. Every call makes a
 * definition, registers it with the manager at once (the way `Route::get()`
 * returns a Route already in the collection), and hands it back to be
 * configured. Every definition, from a line, a resource class or a
 * message class, compiles through CompileStories, so they produce the same
 * registries and the same compile-time guards apply to all of them.
 *
 * SCOPES ARE A STACK, pushed by `Story::for(…)->group(fn)` and popped in
 * `finally`, which is how Laravel's router does route groups. Scopes don't
 * nest: a verb has one object-type scope.
 *
 * `Storyfeed::` stays the facade for recording and reading; this one only
 * defines. One facade per concern, as `Route`, `Schedule` and `Broadcast` are.
 *
 * MIDDLEWARE is the router's, under the router's names. Every publish goes
 * through the `default` group, then its verb's own middleware, minus what
 * the verb excludes:
 *
 *     Story::aliasMiddleware('audit', RecordAudit::class);
 *     Story::middlewareGroup('default', ['batch', 'audit']);
 *
 *     Story::middleware(['audit'])->group(function () {
 *         Story::for(Project::class)->verb('create')->unbatched();
 *     });
 *
 * The package registers `batch` ({@see Batch}) and puts it in `default`, so
 * with nothing declared every verb batches as it always has.
 */
class Registrar
{
    /** The group every verb's middleware starts from. */
    public const DEFAULT_GROUP = 'default';

    /** @var list<array<int, string>> the object types of each open group() */
    protected array $scopes = [];

    /** @var list<list<string|Closure>> the middleware of each open `Story::middleware()->group()` */
    protected array $middlewareScopes = [];

    /** @var list<string> the prefix of each open `Story::name()->group()` */
    protected array $namePrefixes = [];

    /** @var array<string, string|Closure> */
    protected array $middlewareAliases = ['batch' => Batch::class];

    /** @var array<string, list<string|Closure>> */
    protected array $middlewareGroups = [self::DEFAULT_GROUP => ['batch']];

    /**
     * Scope definitions to one or more object types: a model class (resolved
     * through its morph alias), an alias string, or a list of either.
     *
     * @param  string|array<int, string>  $objectType
     */
    public function for(string|array $objectType): TypeScope
    {
        if ($this->scopes !== []) {
            throw StoryMisconfigured::nestedScope();
        }

        return new TypeScope($this, array_values((array) $objectType));
    }

    /**
     * Define a verb: inside a `group()` for the scope's object types, and
     * outside one for any object type (`*.verb`), which is also where group
     * headlines and a composite parent's headline belong.
     *
     * With a class, binds it to the verb instead, as
     * `Route::post('…', ShipOrder::class)` binds a controller: a message
     * class (extends Story) or an invokable one (a public `__invoke`), told
     * apart by the class, as the router tells an invokable controller. The
     * class says what the activity reads as; the binding comes back to take
     * only middleware, as a route bound to a controller does:
     * `Story::verb('create', ProjectWasCreated::class)->unbatched()`. Outside
     * a group, a message class's own `$objectType` names the types, and an
     * invokable class defines the verb for every type.
     *
     * @param  class-string|null  $story
     * @return ($story is null ? Verb : BoundStory)
     */
    public function verb(string|FeedVerb|BackedEnum $verb, ?string $story = null): Verb|BoundStory
    {
        if ($story !== null) {
            return $this->bind(end($this->scopes) ?: null, $verb, $story);
        }

        return $this->define(end($this->scopes) ?: ['*'], $verb);
    }

    /**
     * The fallback for every verb: `type.*` inside a `group()`, `*.*` outside
     * one. It is `Route::fallback()` for headlines, icons and intents.
     */
    public function fallback(): Verb
    {
        return $this->define(end($this->scopes) ?: ['*'], '*');
    }

    /**
     * Define the lifecycle verbs of a model in one line (create, update,
     * delete, restore), as `Route::resource()` defines a controller's
     * actions. Narrow them with `->only()` / `->except()`.
     *
     * With a resource Story class, every public method of it is a verb too:
     * `Story::resource(Order::class, OrderStory::class)`.
     *
     * @param  string|array<int, string>  $objectType  a model class, a morph alias, or a list
     * @param  class-string|null  $class  a resource Story class
     */
    public function resource(string|array $objectType, ?string $class = null): PendingResource
    {
        $resource = new PendingResource($objectType, Verb::caller(), $class, $this->scopedMiddleware(), $this->namePrefix());

        app(StoryfeedManager::class)->addStory($resource);

        return $resource;
    }

    /**
     * Run a group closure with the scope's object types pushed.
     *
     * @param  array<int, string>  $objectTypes
     *
     * @internal Use Story::for(…)->group(…).
     */
    public function scoped(array $objectTypes, Closure $callback, TypeScope $scope): void
    {
        $this->scopes[] = $objectTypes;

        try {
            $callback($scope);
        } finally {
            array_pop($this->scopes);
        }
    }

    /**
     * Bind a message or invokable class to a verb, for these object types
     * or, with none, the message class's own (every type, for an invokable).
     *
     * @param  array<int, string>|null  $objectTypes
     *
     * @internal Use Story::for(…)->verb('complete', TaskWasCompleted::class).
     */
    public function bind(?array $objectTypes, string|FeedVerb|BackedEnum $verb, string $story): BoundStory
    {
        $bound = BoundStory::make($objectTypes, $verb, $story, Verb::caller())
            ->middleware($this->scopedMiddleware())
            ->prefixName($this->namePrefix());

        app(StoryfeedManager::class)->addStory($bound);

        return $bound;
    }

    /**
     * Make a definition, register it, and hand it back to be configured.
     *
     * @param  array<int, string>  $objectTypes
     *
     * @internal
     */
    public function define(array $objectTypes, string|FeedVerb|BackedEnum $verb): Verb
    {
        $definition = Verb::for($objectTypes, $verb, Verb::caller())->scopedToType()->prefixName($this->namePrefix());

        if (($middleware = $this->scopedMiddleware()) !== []) {
            $definition->middleware($middleware);
        }

        app(StoryfeedManager::class)->addStory($definition);

        return $definition;
    }

    /**
     * A name prefix for every definition named inside the group, as
     * `Route::name('admin.')->group(fn)` does:
     *
     *     Story::name('billing.')->group(function () {
     *         Story::for(Invoice::class)->verb('send')->name('invoice.sent');   // billing.invoice.sent
     *     });
     */
    public function name(string $prefix): NameScope
    {
        return new NameScope($this, $prefix);
    }

    /**
     * Whether a story has this name, or every one of these, as `Route::has()`
     * does: a guard before referencing a name built at runtime.
     *
     * @param  string|list<string>  $name
     */
    public function has(string|array $name): bool
    {
        return app(StoryfeedManager::class)->hasNamedStory($name);
    }

    /**
     * Run a group closure with the name prefix pushed.
     *
     * @internal Use Story::name('billing.')->group(…).
     */
    public function withNamePrefix(string $prefix, Closure $callback): void
    {
        $this->namePrefixes[] = $prefix;

        try {
            $callback();
        } finally {
            array_pop($this->namePrefixes);
        }
    }

    /** The prefix of every open `Story::name()->group()`, outermost first. */
    protected function namePrefix(): string
    {
        return implode('', $this->namePrefixes);
    }

    /**
     * Middleware for every definition made inside the group, ahead of each
     * one's own, as `Route::middleware([...])->group(fn)` does:
     *
     *     Story::middleware(['audit'])->group(function () {
     *         Story::for(Invoice::class)->verb('pay');
     *     });
     *
     * @param  string|list<string|Closure>|Closure  $middleware
     */
    public function middleware(string|array|Closure $middleware): MiddlewareScope
    {
        return new MiddlewareScope($this, Verb::middlewareList($middleware));
    }

    /**
     * Name a middleware class (or closure), `Route::aliasMiddleware()`'s
     * twin: `Story::aliasMiddleware('audit', RecordAudit::class)`, then
     * `->middleware('audit')` or `'audit:strict'`.
     */
    public function aliasMiddleware(string $name, string|Closure $class): static
    {
        $this->middlewareAliases[$name] = $class;

        return $this;
    }

    /**
     * Name a list of middleware, `Route::middlewareGroup()`'s twin. The
     * `default` group runs for every verb; any other runs where a verb names
     * it. Defining a group replaces it, so the default group is changed with
     * `Story::middlewareGroup('default', ['batch', 'audit'])`.
     *
     * Aliases and groups are read at each publish, as the router reads its
     * own at each request, so they belong in a service provider's `boot()`,
     * not routes/feed.php: `storyfeed:cache` skips that file, exactly as a
     * cached route file never runs an alias it registers.
     *
     * @param  list<string|Closure>  $middleware
     */
    public function middlewareGroup(string $name, array $middleware): static
    {
        $this->middlewareGroups[$name] = $middleware;

        return $this;
    }

    /** Add to the end of a group, as `Router::pushMiddlewareToGroup()` does. */
    public function pushMiddlewareToGroup(string $group, string|Closure $middleware): static
    {
        if (! in_array($middleware, $this->middlewareGroups[$group] ?? [], true)) {
            $this->middlewareGroups[$group][] = $middleware;
        }

        return $this;
    }

    /** Add to the start of a group, as `Router::prependMiddlewareToGroup()` does. */
    public function prependMiddlewareToGroup(string $group, string|Closure $middleware): static
    {
        $this->middlewareGroups[$group] ??= [];

        if (! in_array($middleware, $this->middlewareGroups[$group], true)) {
            array_unshift($this->middlewareGroups[$group], $middleware);
        }

        return $this;
    }

    /** @return array<string, string|Closure> */
    public function getMiddleware(): array
    {
        return $this->middlewareAliases;
    }

    /** @return array<string, list<string|Closure>> */
    public function getMiddlewareGroups(): array
    {
        return $this->middlewareGroups;
    }

    /**
     * What a verb runs: the default group, then what it declared, minus what
     * it excluded, with aliases and groups resolved and duplicates dropped.
     *
     * @param  list<string|Closure>  $middleware
     * @param  list<string>  $excluded
     * @return list<string|Closure>
     *
     * @internal
     */
    public function gatherMiddleware(array $middleware, array $excluded): array
    {
        return MiddlewareNameResolver::gather(
            [self::DEFAULT_GROUP, ...$middleware],
            $excluded,
            $this->middlewareAliases,
            $this->middlewareGroups,
        );
    }

    /**
     * Run a group closure with the middleware pushed.
     *
     * @param  list<string|Closure>  $middleware
     *
     * @internal Use Story::middleware([...])->group(…).
     */
    public function withMiddleware(array $middleware, Closure $callback): void
    {
        $this->middlewareScopes[] = $middleware;

        try {
            $callback();
        } finally {
            array_pop($this->middlewareScopes);
        }
    }

    /**
     * The middleware of every open `Story::middleware()->group()`, outermost first.
     *
     * @return list<string|Closure>
     */
    protected function scopedMiddleware(): array
    {
        return array_merge(...$this->middlewareScopes);
    }
}
