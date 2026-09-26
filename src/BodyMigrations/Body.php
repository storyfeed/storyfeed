<?php

namespace Storyfeed\BodyMigrations;

use Illuminate\Support\Facades\Facade;

/** @method static void table(class-string<\Storyfeed\Contracts\FeedBody> $body, \Closure(BodyBlueprint): void $callback) */
final class Body extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return BodyMigrator::class;
    }
}
