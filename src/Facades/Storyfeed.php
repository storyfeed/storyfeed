<?php

namespace Storyfeed\Facades;

use Illuminate\Support\Facades\Facade;
use Storyfeed\StoryfeedManager;

/**
 * @method static \Storyfeed\PendingActivity activity(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum|null $verb = null, \Illuminate\Database\Eloquent\Model|string|null $object = null)
 * @method static \Storyfeed\Models\Activity record(string|\Storyfeed\Contracts\FeedVerb|\BackedEnum $verb, \Illuminate\Database\Eloquent\Model|string|null $object = null, \Illuminate\Database\Eloquent\Model|string|null $actor = null, \Illuminate\Database\Eloquent\Model|string|null $target = null, \Illuminate\Database\Eloquent\Model|string|null $context = null, array $data = [], \DateTimeInterface|string|null $publishedAt = null, bool $replace = false)
 * @method static mixed as(\Illuminate\Database\Eloquent\Model|string $actor, ?callable $callback = null)
 * @method static \Storyfeed\FeedBuilder feed()
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
 *
 * @see StoryfeedManager
 */
class Storyfeed extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StoryfeedManager::class;
    }
}
