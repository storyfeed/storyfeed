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
 * class, `Story::verb('x', X::class)`, returns null — the class says it all.
 *
 * @method static \Storyfeed\Stories\TypeScope for(string|array<int, string> $objectType)
 * @method static ($story is null ? \Storyfeed\Stories\Verb : null) verb(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum $verb, ?string $story = null)
 * @method static \Storyfeed\Stories\Verb fallback()
 * @method static \Storyfeed\Stories\PendingResource resource(string|array<int, string> $objectType, ?string $class = null)
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
