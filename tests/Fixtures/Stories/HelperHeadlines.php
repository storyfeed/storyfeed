<?php

namespace Storyfeed\Tests\Fixtures\Stories;

use Closure;

final class HelperHeadlines
{
    public static function arrow(): Closure
    {
        return static fn (array $activity): string => filled($activity['tier']) ? ':actor confirmed :object at a tier' : ':actor confirmed :object';
    }

    public static function block(): Closure
    {
        return static function (array $activity): string {
            return filled($activity['tier']) ? ':actor confirmed :object at a tier' : ':actor confirmed :object';
        };
    }

    public static function qualified(): Closure
    {
        return static fn (array $activity): string => \filled($activity['tier']) ? ':actor confirmed :object at a tier' : ':actor confirmed :object';
    }
}
