<?php

namespace Storyfeed\Facades;

use Illuminate\Support\Facades\Facade;
use Storyfeed\StoryfeedManager;
use Storyfeed\Testing\StoryfeedFake;

/**
 * @method static \Storyfeed\PendingActivity activity(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum|null $verb = null, \Illuminate\Database\Eloquent\Model|string|null $object = null)
 * @method static \Storyfeed\Models\Activity record(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum $verb, \Illuminate\Database\Eloquent\Model|string|null $object = null, \Illuminate\Database\Eloquent\Model|string|null $actor = null, \Illuminate\Database\Eloquent\Model|string|null $target = null, \Illuminate\Database\Eloquent\Model|string|null $context = null, array $data = [], \DateTimeInterface|string|null $publishedAt = null, bool $replace = false)
 * @method static mixed as(\Illuminate\Database\Eloquent\Model|string $actor, ?callable $callback = null)
 * @method static void assertPublished(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum|\Closure $verb, ?\Illuminate\Database\Eloquent\Model $object = null)
 * @method static void assertNotPublished(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum|\Closure $verb, ?\Illuminate\Database\Eloquent\Model $object = null)
 * @method static void assertPublishedCount(int $count, string|\Storyfeed\Contracts\FeedVerb|\BackedEnum|\Closure|null $verb = null)
 * @method static void assertNothingPublished()
 * @method static \Illuminate\Support\Collection published(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum|\Closure|null $verb = null)
 * @method static \Storyfeed\FeedBuilder feed(?string $preset = null)
 * @method static \Storyfeed\StoryfeedManager grammar(array $grammar, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager icons(array $icons, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager verbs(array|string $verbs, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager objectTypes(array $objectTypes, bool $merge = true)
 * @method static string|\Closure|null template(?string $type, string $verb)
 * @method static string|null icon(?string $type, string $verb)
 * @method static \Storyfeed\ActivityStreams\ActivityType|string|null activityType(string $verb)
 * @method static string activityTypeValue(string $verb)
 * @method static \Storyfeed\ActivityStreams\ObjectType|string|null objectType(string $alias)
 * @method static string objectTypeValue(string $alias)
 * @method static array registeredGrammar()
 * @method static array registeredIcons()
 * @method static array registeredVerbs()
 * @method static void resolveActorUsing(\Closure $resolver)
 * @method static \Illuminate\Database\Eloquent\Model|null resolveActor()
 * @method static \Storyfeed\StoryfeedManager aggregateGrammar(array $grammar, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager axes(array $axes, bool $merge = true, ?string $before = null)
 * @method static \Storyfeed\StoryfeedManager collectables(array $aliases, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager stories(array $stories, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager checks(array $checks, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager feeds(array $feeds, bool $merge = true)
 * @method static array registeredFeeds()
 * @method static array feedNames()
 * @method static \Storyfeed\FeedDefinition feedDefinition(string $preset)
 * @method static \Storyfeed\StoryfeedManager useCompiledStories(array $compiled)
 * @method static void compileStories()
 * @method static array compiledStories()
 * @method static array storyDefinitions()
 * @method static array registeredStories()
 * @method static array registeredAggregateGrammar()
 * @method static string|\Closure|null aggregateTemplate(?string $axis, string $verb)
 * @method static array|null aggregateTokens(string $axis)
 * @method static array registeredAxes()
 * @method static \Storyfeed\Grouping\Axis|null axis(string $name)
 * @method static array aggregateAxes()
 * @method static array rowBackedBuckets()
 * @method static \Storyfeed\Grouping\Axis|null fallbackAxis()
 * @method static array axesApplicableTo(array $filledRoles)
 * @method static array possibleAggregatePairs(array $roleMap)
 * @method static string|null templateKey(?string $type, string $verb)
 * @method static string|null iconKey(?string $type, string $verb)
 * @method static string|null aggregateTemplateKey(?string $axis, string $verb)
 * @method static bool declaredVerb(string $verb)
 * @method static bool isCollectable(?string $alias)
 * @method static \Storyfeed\Models\Party party(string $name)
 * @method static \Storyfeed\Diagnostics\Report doctor(array $only = [])
 * @method static array checkNames()
 *
 * @see StoryfeedManager
 */
class Storyfeed extends Facade
{
    /**
     * Record activities in memory instead of persisting them.
     *
     *   Storyfeed::fake();
     *   // ... exercise the code under test
     *   Storyfeed::assertPublished('confirm', $delivery);
     *
     * Lives on the facade rather than the manager because the facade caches
     * its resolved root — swapping only the container binding would leave
     * the cached instance in place.
     */
    public static function fake(): StoryfeedFake
    {
        $fake = (new StoryfeedFake)->inheritFrom(static::getFacadeRoot());

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return StoryfeedManager::class;
    }
}
