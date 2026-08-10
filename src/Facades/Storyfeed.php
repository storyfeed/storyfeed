<?php

namespace Storyfeed\Storyfeed\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @see \Storyfeed\Storyfeed\Storyfeed
 */
class Storyfeed extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Storyfeed\Storyfeed\Storyfeed::class;
    }
}
