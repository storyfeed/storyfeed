<?php

namespace Storyfeed\Facades;

use Illuminate\Support\Facades\Facade;
use Storyfeed\StoryfeedManager;

/**
 * @method static \Storyfeed\PendingActivity activity(...$args)
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
