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
 * @method static \Storyfeed\Stories\TypeScope for(string|array<int, string> $objectType)
 * @method static \Storyfeed\Stories\Verb verb(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum $verb)
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
