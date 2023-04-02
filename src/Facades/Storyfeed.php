<?php

namespace Storyfeed\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Storyfeed\Storyfeed
 *
 * @method static \Storyfeed\Support\ActivityBuilder activity(...$args)
 */
class Storyfeed extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Storyfeed\Storyfeed::class;
    }
}
