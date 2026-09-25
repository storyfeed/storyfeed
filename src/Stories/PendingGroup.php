<?php

namespace Storyfeed\Stories;

use BackedEnum;
use Closure;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\FeedNoun;

/**
 * The attributes of a group of definitions, gathered in any order and
 * applied by `group()`, as `Route::middleware()`, `Route::as()` and the rest
 * return a RouteRegistrar (Illuminate/Routing/RouteRegistrar.php):
 *
 *     Story::for(Order::class)->middleware('audit')->as('billing.')->group(function () {
 *         Story::verb('refund')->name('order.refund');     // billing.order.refund, with audit
 *     });
 *
 *     Story::middleware('audit')->whereActor(User::class)->group(fn () => …);
 *
 * Each of `Story::for()`, `Story::middleware()`, `Story::withoutMiddleware()`,
 * `Story::as()` / `Story::name()` and the role constraints starts one, and
 * the rest chain onto it. Groups nest, and an inner group's attributes merge
 * into the outer's as `RouteGroup::merge()` merges them: names concatenate,
 * middleware and exclusions append, and a role constraint replaces the
 * outer one for that role, as an inner `where` replaces a parameter's. Only
 * the object types don't nest: a verb has one type scope.
 *
 * Without `group()`, it defines directly, as `Route::middleware('auth')
 * ->get(…)` does, with its attributes:
 *
 *     Story::for(Order::class)->verb('place')->headline(':actor placed :object');
 *     Story::middleware('audit')->resource(Invoice::class);
 *
 *     Story::for(Order::class)
 *         ->verb('place', fn (Verb $verb) => $verb->headline(':actor placed :object'))
 *         ->verb('complete', fn (Verb $verb) => $verb->headline(':actor completed :object'));
 *
 *     Story::for(MenuItem::class)->noun('dish|dishes');
 *
 * The group closure receives this object, for anyone who prefers
 * `fn (PendingGroup $order) => $order->verb('place')`.
 */
final class PendingGroup
{
    use CreatesRoleConstraints;

    /** @var array<int, string>|null the object types; null says none */
    private ?array $objectTypes = null;

    /** @var list<string|Closure> */
    private array $middleware = [];

    /** @var list<string> */
    private array $excludedMiddleware = [];

    private string $as = '';

    /** @var array<string, list<string>> */
    private array $wheres = [];

    public function __construct(
        private readonly Registrar $registrar,
    ) {}

    /**
     * Scope the group to one or more object types: a model class (resolved
     * through its morph alias), an alias string, or a list of either.
     *
     * @param  string|array<int, string>  $objectType
     */
    public function for(string|array $objectType): self
    {
        $this->registrar->assertNotTypeScoped();

        $this->objectTypes = array_values((array) $objectType);

        return $this;
    }

    /**
     * Middleware for every definition in the group, ahead of each one's own.
     * Appends, as the group's middleware does when groups nest.
     *
     * @param  string|list<string|Closure>|Closure  $middleware
     */
    public function middleware(string|array|Closure $middleware): self
    {
        $this->middleware = [...$this->middleware, ...Verb::middlewareList($middleware)];

        return $this;
    }

    /**
     * Middleware taken out of every definition in the group.
     *
     * @param  string|list<string>  $middleware
     */
    public function withoutMiddleware(string|array $middleware): self
    {
        $this->excludedMiddleware = [...$this->excludedMiddleware, ...(array) $middleware];

        return $this;
    }

    /**
     * A prefix for the name of every definition named in the group. Only a
     * name is prefixed: a verb in the group that isn't named stays unnamed.
     */
    public function as(string $prefix): self
    {
        $this->as .= $prefix;

        return $this;
    }

    /** Alias for as(), following Laravel's name-to-as route attribute alias. */
    public function name(string $prefix): self
    {
        return $this->as($prefix);
    }

    /**
     * @param  string|list<string>  ...$types
     *
     * @see Verb::whereRole()
     */
    public function whereRole(string $role, string|array ...$types): static
    {
        $this->wheres[$role] = Verb::roleTypes($role, $types, "A Story group's ->whereRole('{$role}', …)");

        return $this;
    }

    /**
     * Run the closure with these attributes open, merged into any enclosing
     * group's, and popped in `finally`, as `Router::group()` does.
     *
     * @param  Closure(PendingGroup): mixed  $callback
     */
    public function group(Closure $callback): self
    {
        $this->registrar->group($this, $callback);

        return $this;
    }

    /**
     * The attributes, in the shape the registrar's group stack keeps.
     *
     * @return array{types: array<int, string>|null, middleware: list<string|Closure>, excluded_middleware: list<string>, as: string, where: array<string, list<string>>}
     *
     * @internal
     */
    public function attributes(): array
    {
        return [
            'types' => $this->objectTypes,
            'middleware' => $this->middleware,
            'excluded_middleware' => $this->excludedMiddleware,
            'as' => $this->as,
            'where' => $this->wheres,
        ];
    }

    /**
     * Define a verb in the group. Without a closure, returns the verb's
     * definition to configure; with one, configures it and returns this
     * group, so more `->verb()` calls chain.
     *
     * With a class, message or invokable, binds it to the verb, as a route
     * binds a controller, and returns the binding, as `Story::verb()` does,
     * to take a name or middleware:
     * `->verb('ship', ShipStory::class)->name('order.ship')`.
     *
     * @template TConfigure of (Closure(Verb): mixed)|string|null
     *
     * @param  TConfigure  $configure
     * @return (TConfigure is null ? Verb : (TConfigure is string ? BoundStory : self))
     */
    public function verb(string|FeedVerb|BackedEnum $verb, Closure|string|null $configure = null): Verb|BoundStory|self
    {
        if (is_string($configure)) {
            return $this->registrar->within($this, fn () => $this->registrar->verb($verb, $configure));
        }

        $definition = $this->registrar->within($this, fn () => $this->registrar->verb($verb));

        if ($configure === null) {
            return $definition;
        }

        $configure($definition);

        return $this;
    }

    /**
     * The fallback for the group's types, `type.*` (or `*.*` without any).
     * Without a closure, returns its definition; with one, configures it and
     * returns this group.
     *
     * @template TConfigure of (Closure(Verb): mixed)|null
     *
     * @param  TConfigure  $configure
     * @return (TConfigure is null ? Verb : self)
     */
    public function fallback(?Closure $configure = null): Verb|self
    {
        $definition = $this->registrar->within($this, fn () => $this->registrar->fallback());

        if ($configure === null) {
            return $definition;
        }

        $configure($definition);

        return $this;
    }

    /**
     * The group's resource, with its attributes, as
     * `Route::middleware('auth')->resource(…)` is.
     *
     * @param  string|array<int, string>  $objectType
     * @param  class-string|null  $class
     */
    public function resource(string|array $objectType, ?string $class = null): PendingResource
    {
        return $this->registrar->within($this, fn () => $this->registrar->resource($objectType, $class));
    }

    /**
     * The group's resources, with its attributes.
     *
     * @param  array<string, class-string|null>  $resources
     * @param  array<string, mixed>  $options
     *
     * @see Registrar::resources()
     */
    public function resources(array $resources, array $options = []): void
    {
        $this->registrar->within($this, fn () => $this->registrar->resources($resources, $options));
    }

    /**
     * The plural forms of the thing these types are, `'dish|dishes'`, used
     * where a group can't name one entity. Both forms are required.
     */
    public function noun(string|FeedNoun $noun): self
    {
        $this->fallback()->noun($noun);

        return $this;
    }

    /**
     * The roles every verb on these types is about, `type.*`: once one is a
     * tombstone, the activity is redundant. Replaces the default set (the
     * object); with no roles, none. A verb's own `->missing()` wins.
     */
    public function missing(string ...$roles): self
    {
        $this->fallback()->missing(...$roles);

        return $this;
    }

    /**
     * The AS2.0 object type these types serialize as — the registry form of
     * HasActivityStreamsType.
     */
    public function activityStreamsType(ObjectType|string $type): self
    {
        $this->fallback()->activityStreamsType($type);

        return $this;
    }
}
