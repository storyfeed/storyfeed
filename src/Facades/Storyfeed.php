<?php

namespace Storyfeed\Facades;

use Illuminate\Support\Facades\Facade;
use Storyfeed\StoryfeedManager;

/**
 * @method static \Storyfeed\PendingActivity activity(...$args)
 * @method static \Storyfeed\FeedBuilder feed()
 * @method static \Storyfeed\StoryfeedManager grammar(array $grammar, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager icons(array $icons, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager verbs(array $verbs, bool $merge = true)
 * @method static \Storyfeed\StoryfeedManager objectTypes(array $objectTypes, bool $merge = true)
 * @method static string|\Closure|null template(?string $type, string $verb)
 * @method static string|null icon(?string $type, string $verb)
 * @method static string|null activityType(string $verb)
 * @method static string|null objectType(string $alias)
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
