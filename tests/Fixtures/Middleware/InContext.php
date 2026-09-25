<?php

namespace Storyfeed\Tests\Fixtures\Middleware;

use Closure;
use Storyfeed\PendingActivity;

/** A default context, set only when nobody has said. */
class InContext
{
    public function handle(PendingActivity $activity, Closure $next, string $party): mixed
    {
        if (! $activity->has('context')) {
            $activity->context($party);
        }

        return $next($activity);
    }
}
