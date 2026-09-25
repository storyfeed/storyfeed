<?php

namespace Storyfeed\Stories;

use BackedEnum;
use Closure;
use InvalidArgumentException;
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
 *     Story::resources([Order::class => OrderStory::class, Document::class => null]);
 *     Story::for(Task::class)->verb('complete', TaskWasCompleted::class);   // a message class
 *     Story::verb('confirm', ConfirmStory::class);                          // an invokable class, every type
 *     Story::for(Order::class)->verb('refund')->whereActor(User::class, 'party');   // who may refund
 *
 *     Story::for(Order::class)->verb('confirm')->name('checkout.confirm');   // story('checkout.confirm', $order)
 *     Story::as('billing.')->group(fn () => …);                              // billing.…
 *
 * WHAT IT IS. A front door onto {@see Verb}. Every call makes a
 * definition, registers it with the manager at once (the way `Route::get()`
 * returns a Route already in the collection), and hands it back to be
 * configured. Every definition, from a line, a resource class or a
 * message class, compiles through CompileStories, so they produce the same
 * registries and the same compile-time guards apply to all of them.
 *
 * GROUPS ARE A STACK, as the router's are. `Story::for()`, `middleware()`,
 * `withoutMiddleware()`, `as()` / `name()` and the `where…()` role
 * constraints each start a PendingGroup, the rest chain onto it in any
 * order, and `->group(fn)` pushes it, merged into the enclosing group
 * (see mergeWithLastGroup()), and pops it in `finally`. Object types don't
 * nest: a verb has one object-type scope.
 *
 *     Story::for(Order::class)->middleware('audit')->as('billing.')->group(fn () => …);
 *     Story::whereActor(User::class)->group(fn () => …);
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

    /** The options `Story::resources()` takes, by `Route::resource()`'s names. */
    public const RESOURCE_OPTIONS = ['only', 'except', 'middleware', 'excluded_middleware', 'wheres'];

    /**
     * The open groups, innermost last, each already merged into the one
     * around it, as the router's `$groupStack` is.
     *
     * @var list<array{types: array<int, string>|null, middleware: list<string|Closure>, excluded_middleware: list<string>, as: string, where: array<string, list<string>>, group: PendingGroup}>
     */
    protected array $groupStack = [];

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
    public function for(string|array $objectType): PendingGroup
    {
        return (new PendingGroup($this))->for($objectType);
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
            return $this->bind($this->groupTypes(), $verb, $story);
        }

        return $this->define($this->groupTypes() ?? ['*'], $verb);
    }

    /**
     * The fallback for every verb: `type.*` inside a `group()`, `*.*` outside
     * one. It is `Route::fallback()` for headlines, icons and intents.
     */
    public function fallback(): Verb
    {
        return $this->define($this->groupTypes() ?? ['*'], '*');
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
        $group = $this->currentGroup();

        $resource = (new PendingResource($objectType, Verb::caller(), $class, $group['middleware'], $group['as']))
            ->withoutMiddleware($group['excluded_middleware'])
            ->whereRoles($group['where']);

        app(StoryfeedManager::class)->addStory($resource);

        return $resource;
    }

    /**
     * Several resources in one call, `model => resource class` (or null for
     * the four lifecycle verbs alone), as `Route::resources()` registers
     * several controllers (Illuminate/Routing/Router.php, resources()).
     * The options apply to each, and are PendingResource's methods by
     * the names `Route::resource()` takes them: `only`, `except`,
     * `middleware`, `excluded_middleware` and `wheres` (role => types).
     *
     *     Story::resources([
     *         Order::class => OrderStory::class,
     *         Document::class => null,
     *     ], ['except' => ['restore']]);
     *
     * @param  array<string, class-string|null>  $resources
     * @param  array<string, mixed>  $options
     */
    public function resources(array $resources, array $options = []): void
    {
        $unknown = array_diff(array_keys($options), self::RESOURCE_OPTIONS);

        if ($unknown !== []) {
            throw new InvalidArgumentException(
                'Story::resources() has no ['.implode('], [', $unknown).'] option. It takes '.implode(', ', self::RESOURCE_OPTIONS).'.',
            );
        }

        foreach ($resources as $objectType => $class) {
            $resource = $this->resource($objectType, $class);

            if (isset($options['only'])) {
                /** @var string|list<string> $only */
                $only = $options['only'];
                $resource->only($only);
            }

            if (isset($options['except'])) {
                /** @var string|list<string> $except */
                $except = $options['except'];
                $resource->except($except);
            }

            if (isset($options['middleware'])) {
                /** @var string|list<string|Closure>|Closure $middleware */
                $middleware = $options['middleware'];
                $resource->middleware($middleware);
            }

            if (isset($options['excluded_middleware'])) {
                /** @var string|list<string> $excluded */
                $excluded = $options['excluded_middleware'];
                $resource->withoutMiddleware($excluded);
            }

            /** @var array<string, string|list<string>> $wheres */
            $wheres = $options['wheres'] ?? [];

            foreach ($wheres as $role => $types) {
                $resource->whereRole($role, $types);
            }
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
        $group = $this->currentGroup();

        $bound = BoundStory::make($objectTypes, $verb, $story, Verb::caller())
            ->middleware($group['middleware'])
            ->withoutMiddleware($group['excluded_middleware'])
            ->whereRoles($group['where'])
            ->prefixName($group['as']);

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
        $group = $this->currentGroup();

        $definition = Verb::for($objectTypes, $verb, Verb::caller())
            ->scopedToType()
            ->prefixName($group['as'])
            ->whereRoles($group['where']);

        if ($group['middleware'] !== []) {
            $definition->middleware($group['middleware']);
        }

        if ($group['excluded_middleware'] !== []) {
            $definition->withoutMiddleware($group['excluded_middleware']);
        }

        app(StoryfeedManager::class)->addStory($definition);

        return $definition;
    }

    /**
     * A name prefix for every definition named inside the group, as
     * `Route::as('admin.')->group(fn)` does:
     *
     *     Story::as('billing.')->group(function () {
     *         Story::for(Invoice::class)->verb('send')->name('invoice.sent');   // billing.invoice.sent
     *     });
     */
    public function as(string $prefix): PendingGroup
    {
        return (new PendingGroup($this))->as($prefix);
    }

    /** Alias for as(), following Laravel's name-to-as route attribute alias. */
    public function name(string $prefix): PendingGroup
    {
        return $this->as($prefix);
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
     * Middleware for every definition made inside the group, ahead of each
     * one's own, as `Route::middleware([...])->group(fn)` does:
     *
     *     Story::middleware(['audit'])->group(function () {
     *         Story::for(Invoice::class)->verb('pay');
     *     });
     *
     * @param  string|list<string|Closure>|Closure  $middleware
     */
    public function middleware(string|array|Closure $middleware): PendingGroup
    {
        return (new PendingGroup($this))->middleware($middleware);
    }

    /**
     * Middleware taken out of every definition made inside the group, as
     * `Route::withoutMiddleware([...])->group(fn)` does.
     *
     * @param  string|list<string>  $middleware
     */
    public function withoutMiddleware(string|array $middleware): PendingGroup
    {
        return (new PendingGroup($this))->withoutMiddleware($middleware);
    }

    /**
     * The types a role may be, for every definition made inside the group,
     * as `Route::where([...])->group(fn)` constrains its routes' parameters.
     *
     * @param  string|list<string>  ...$types
     *
     * @see Verb::whereRole()
     */
    public function whereRole(string $role, string|array ...$types): PendingGroup
    {
        return (new PendingGroup($this))->whereRole($role, ...$types);
    }

    /**
     * @param  string|list<string>  ...$types
     *
     * @see Verb::whereRole()
     */
    public function whereActor(string|array ...$types): PendingGroup
    {
        return $this->whereRole('actor', ...$types);
    }

    /**
     * @param  string|list<string>  ...$types
     *
     * @see Verb::whereRole()
     */
    public function whereObject(string|array ...$types): PendingGroup
    {
        return $this->whereRole('object', ...$types);
    }

    /**
     * @param  string|list<string>  ...$types
     *
     * @see Verb::whereRole()
     */
    public function whereTarget(string|array ...$types): PendingGroup
    {
        return $this->whereRole('target', ...$types);
    }

    /**
     * @param  string|list<string>  ...$types
     *
     * @see Verb::whereRole()
     */
    public function whereContext(string|array ...$types): PendingGroup
    {
        return $this->whereRole('context', ...$types);
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
     * Run a group closure with its attributes merged into the enclosing
     * group's and pushed, then popped in `finally`: `Router::group()`,
     * `updateGroupStack()` and `mergeWithLastGroup()`.
     *
     * @param  Closure(PendingGroup): mixed  $callback
     *
     * @internal Use Story::for(…)->group(…), Story::middleware(…)->group(…), …
     */
    public function group(PendingGroup $group, Closure $callback): void
    {
        $this->groupStack[] = [...$this->mergeWithLastGroup($group->attributes()), 'group' => $group];

        try {
            $callback($group);
        } finally {
            array_pop($this->groupStack);
        }
    }

    /**
     * Run a definition call with a pending group's attributes, as
     * `Route::middleware('auth')->get(…)` gives one route a registrar's
     * attributes: in a group of its own, unless that group is already open
     * (its closure calling `$group->verb()`), so nothing applies twice.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     *
     * @internal
     */
    public function within(PendingGroup $group, Closure $callback): mixed
    {
        if ($this->groupStack !== [] && end($this->groupStack)['group'] === $group) {
            return $callback();
        }

        $result = null;

        $this->group($group, function () use ($callback, &$result) {
            $result = $callback();
        });

        return $result;
    }

    /**
     * Refuse a second object-type scope inside the first.
     *
     * @internal
     */
    public function assertNotTypeScoped(): void
    {
        if ($this->groupTypes() !== null) {
            throw StoryMisconfigured::nestedScope();
        }
    }

    /**
     * A group's attributes merged into the enclosing group's, as
     * `RouteGroup::merge()` merges them: the prefix concatenates (formatAs),
     * middleware and exclusions append (array_merge_recursive), and a role
     * constraint replaces the enclosing one for its role (formatWhere). The
     * types don't merge: a verb has one type scope.
     *
     * @param  array{types: array<int, string>|null, middleware: list<string|Closure>, excluded_middleware: list<string>, as: string, where: array<string, list<string>>}  $new
     * @return array{types: array<int, string>|null, middleware: list<string|Closure>, excluded_middleware: list<string>, as: string, where: array<string, list<string>>}
     */
    protected function mergeWithLastGroup(array $new): array
    {
        $old = $this->currentGroup();

        if ($new['types'] !== null && $old['types'] !== null) {
            throw StoryMisconfigured::nestedScope();
        }

        return [
            'types' => $new['types'] ?? $old['types'],
            'middleware' => [...$old['middleware'], ...$new['middleware']],
            'excluded_middleware' => [...$old['excluded_middleware'], ...$new['excluded_middleware']],
            'as' => $old['as'].$new['as'],
            'where' => [...$old['where'], ...$new['where']],
        ];
    }

    /**
     * The innermost open group's attributes, merged; empty ones outside any.
     *
     * @return array{types: array<int, string>|null, middleware: list<string|Closure>, excluded_middleware: list<string>, as: string, where: array<string, list<string>>}
     */
    protected function currentGroup(): array
    {
        if ($this->groupStack === []) {
            return ['types' => null, 'middleware' => [], 'excluded_middleware' => [], 'as' => '', 'where' => []];
        }

        $group = end($this->groupStack);
        unset($group['group']);

        return $group;
    }

    /** @return array<int, string>|null the open group's object types, or null outside one */
    protected function groupTypes(): ?array
    {
        return $this->currentGroup()['types'];
    }
}
