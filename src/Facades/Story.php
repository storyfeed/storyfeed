<?php

namespace Storyfeed\Facades;

use Illuminate\Support\Facades\Facade;
use Storyfeed\StoryManager;

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
 * Shares its short name with the `Storyfeed\Story` base class, which Story
 * classes extend. The two rarely meet in one file; where they do, alias one:
 * `use Storyfeed\Story as BaseStory;`.
 *
 * @method static \Storyfeed\TypeScope for(string|array<int, string> $objectType)
 * @method static \Storyfeed\StoryDefinition verb(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum $verb)
 * @method static \Storyfeed\StoryDefinition fallback()
 * @method static \Storyfeed\PendingResource resource(string|array<int, string> $objectType)
 *
 * @see StoryManager
 */
class Story extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StoryManager::class;
    }
}
