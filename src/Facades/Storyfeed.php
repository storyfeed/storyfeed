<?php

namespace Storyfeed\Facades;

use Illuminate\Support\Facades\Facade;
use Storyfeed\StoryfeedManager;
use Storyfeed\Testing\StoryfeedFake;

/**
 * @method static StoryfeedManager actorlessGrammar(array<array-key, string|\Closure> $grammar, bool $merge = true)
 * @method static string|\Closure|null actorlessTemplate(string $verb)
 * @method static \Storyfeed\PendingActivity activity(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum|null $verb = null, \Illuminate\Database\Eloquent\Model|string|null $object = null)
 * @method static \Storyfeed\Models\Activity record(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum $verb, \Illuminate\Database\Eloquent\Model|string|null $object = null, \Illuminate\Database\Eloquent\Model|string|null $actor = null, \Illuminate\Database\Eloquent\Model|string|null $target = null, \Illuminate\Database\Eloquent\Model|string|null $context = null, array<string, mixed> $data = [], \DateTimeInterface|string|null $publishedAt = null, bool $replace = false, iterable<int, \Illuminate\Database\Eloquent\Model> $objects = [], \Storyfeed\FeedThread|null $thread = null, \Illuminate\Database\Eloquent\Model|string|null $origin = null, \Illuminate\Database\Eloquent\Model|string|null $result = null, \Illuminate\Database\Eloquent\Model|string|null $instrument = null)
 * @method static \Storyfeed\PendingActivity anonymous()
 * @method static mixed as(\Illuminate\Database\Eloquent\Model|string $actor, ?callable $callback = null)
 * @method static bool isRecording()
 * @method static \Storyfeed\StoryfeedManager stopRecording()
 * @method static \Storyfeed\StoryfeedManager startRecording()
 * @method static mixed withoutRecording(callable $callback)
 * @method static mixed recording(callable $callback)
 * @method static void assertPublished(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum|\Closure $verb, ?\Illuminate\Database\Eloquent\Model $object = null)
 * @method static void assertNotPublished(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum|\Closure $verb, ?\Illuminate\Database\Eloquent\Model $object = null)
 * @method static void assertPublishedCount(int $count, string|\Storyfeed\Contracts\FeedVerb|\BackedEnum|\Closure|null $verb = null)
 * @method static void assertNothingPublished()
 * @method static \Illuminate\Support\Collection<int, \Storyfeed\Models\Activity> published(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum|\Closure|null $verb = null)
 * @method static \Storyfeed\FeedBuilder feed(?string $preset = null)
 * @method static \Storyfeed\StoryfeedManager grammar(array<array-key, string|\Closure> $grammar, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager icons(array<array-key, string> $icons, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager nouns(array<array-key, string|\Storyfeed\FeedNoun> $nouns, bool $merge = true)
 * @method static string|\Storyfeed\FeedNoun|null noun(?string $type, string $verb)
 * @method static array<string, string|\Storyfeed\FeedNoun> registeredNouns()
 * @method static \Storyfeed\StoryfeedManager verbs(array<array-key, \Storyfeed\ActivityStreams\ActivityType|string>|class-string $verbs, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager objectTypes(array<array-key, \Storyfeed\ActivityStreams\ObjectType|string> $objectTypes, bool $merge = true)
 * @method static string|\Closure|null template(?string $type, string $verb)
 * @method static string|null icon(?string $type, string $verb)
 * @method static \Storyfeed\ActivityStreams\ActivityType|string|null activityType(string $verb)
 * @method static string activityTypeValue(string $verb)
 * @method static \Storyfeed\ActivityStreams\ObjectType|string|null objectType(string $alias)
 * @method static string objectTypeValue(string $alias)
 * @method static array<string, string|\Closure> registeredGrammar()
 * @method static array<string, string> registeredIcons()
 * @method static array<string, \Storyfeed\ActivityStreams\ActivityType|string> registeredVerbs()
 * @method static void resolveActorUsing(\Closure $resolver)
 * @method static \Illuminate\Database\Eloquent\Model|null resolveActor()
 * @method static \Storyfeed\StoryfeedManager aggregateGrammar(array<array-key, string|\Closure> $grammar, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager axes(array<int, \Storyfeed\Grouping\Axis> $axes, bool $merge = true, ?string $before = null)
 * @method static \Storyfeed\StoryfeedManager collectables(array<int, string> $aliases, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager stories(array<int|string, class-string<\Storyfeed\Story>|\Storyfeed\StoryDefinition|array<string, mixed>> $stories, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager checks(array<int, class-string<\Storyfeed\Contracts\DiagnosticCheck>|\Storyfeed\Contracts\DiagnosticCheck> $checks, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager feeds(array<int|string, \Closure|\Storyfeed\Feed|class-string<\Storyfeed\Feed>> $feeds, bool $merge = true)
 * @method static array<string, \Storyfeed\FeedDefinition> registeredFeeds()
 * @method static \Storyfeed\StoryfeedManager healers(list<class-string<\Storyfeed\Contracts\FeedHealer>|\Storyfeed\Contracts\FeedHealer> $healers, bool $merge = true)
 * @method static array<string, \Storyfeed\Contracts\FeedHealer> registeredHealers()
 * @method static list<string> feedNames()
 * @method static string|null feedNameFor(string $class)
 * @method static \Storyfeed\FeedDefinition feedDefinition(string $preset)
 * @method static \Storyfeed\StoryfeedManager useCompiledStories(array{grammar: array<string, string>, aggregateGrammar: array<string, string>, icons: array<string, string>, verbs: array<string, mixed>} $compiled)
 * @method static void compileStories()
 * @method static array{grammar: array<string, string>, aggregateGrammar: array<string, string>, icons: array<string, string>, verbs: array<string, mixed>} compiledStories()
 * @method static array<int, \Storyfeed\StoryDefinition> storyDefinitions()
 * @method static array<int|string, mixed> registeredStories()
 * @method static array<string, string|\Closure> registeredAggregateGrammar()
 * @method static string|\Closure|null aggregateTemplate(?string $axis, string $verb)
 * @method static array<int, string>|null aggregateTokens(string $axis)
 * @method static bool pinsType(string $axis, string $role)
 * @method static array<string, \Storyfeed\Grouping\Axis> registeredAxes()
 * @method static \Storyfeed\Grouping\Axis|null axis(string $name)
 * @method static array<int, string> aggregateAxes()
 * @method static array<int, string> rowBackedBuckets()
 * @method static \Storyfeed\Grouping\Axis|null fallbackAxis()
 * @method static array<int, string> axesApplicableTo(array<int, string> $filledRoles)
 * @method static array<int, array{0: string, 1: string}> possibleAggregatePairs(array<string, array<int, string>> $roleMap)
 * @method static string|null templateKey(?string $type, string $verb)
 * @method static string|null iconKey(?string $type, string $verb)
 * @method static string|null aggregateTemplateKey(?string $axis, string $verb)
 * @method static bool declaredVerb(string $verb)
 * @method static bool isCollectable(?string $alias)
 * @method static \Storyfeed\Models\Party party(string $name)
 * @method static \Storyfeed\Diagnostics\Report doctor(array<int, string> $only = [])
 * @method static array<int, string> checkNames()
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
