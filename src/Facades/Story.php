<?php

namespace Storyfeed\Facades;

use Illuminate\Support\Facades\Facade;
use Storyfeed\Stories\Registrar;

/**
 * Define what activities say, Route-style. `Storyfeed` records and reads;
 * `Story` defines.
 *
 *     use Storyfeed\Facades\Story;
 *
 *     Story::for(Order::class)->group(function () {
 *         Story::verb('place')->headline(':actor placed :object[ with :target]');
 *     });
 *
 * Shares its short name with the `Storyfeed\Stories\Story` base class, which
 * Story classes extend. The two rarely meet in one file; where they do, alias
 * one: `use Storyfeed\Stories\Story as BaseStory;`.
 *
 * `Story::verb('x')` returns the definition to configure; binding a message
 * or invokable class, `Story::verb('x', X::class)`, returns the binding, which takes only
 * middleware — the class says the rest.
 *
 * @method static \Storyfeed\Stories\TypeScope for(string|array<int, string> $objectType)
 * @method static ($story is null ? \Storyfeed\Stories\Verb : \Storyfeed\Stories\BoundStory) verb(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum $verb, ?string $story = null)
 * @method static \Storyfeed\Stories\Verb fallback()
 * @method static \Storyfeed\Stories\PendingResource resource(string|array<int, string> $objectType, ?string $class = null)
 * @method static \Storyfeed\Stories\NameScope name(string $prefix)
 * @method static bool has(string|list<string> $name)
 * @method static \Storyfeed\Stories\MiddlewareScope middleware(string|list<string|\Closure>|\Closure $middleware)
 * @method static \Storyfeed\Stories\Registrar aliasMiddleware(string $name, string|\Closure $class)
 * @method static \Storyfeed\Stories\Registrar middlewareGroup(string $name, list<string|\Closure> $middleware)
 * @method static \Storyfeed\Stories\Registrar pushMiddlewareToGroup(string $group, string|\Closure $middleware)
 * @method static \Storyfeed\Stories\Registrar prependMiddlewareToGroup(string $group, string|\Closure $middleware)
 * @method static array<string, string|\Closure> getMiddleware()
 * @method static array<string, list<string|\Closure>> getMiddlewareGroups()
 *
 * @see Registrar
 */
class Story extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Registrar::class;
    }
}
