<?php

namespace Storyfeed\Tests\Fixtures\Middleware;

use Closure;
use Storyfeed\PendingActivity;

/** A default actor, as a well-behaved middleware sets one: only when nobody has said. */
class ActAs
{
    public function handle(PendingActivity $activity, Closure $next, string $party): mixed
    {
        if (! $activity->hasActor()) {
            $activity->actor($party);
        }

        return $next($activity);
    }
}
