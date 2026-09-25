<?php

namespace Storyfeed\Tests\Fixtures\Middleware;

use Closure;
use Storyfeed\Models\Activity;
use Storyfeed\PendingActivity;

/** Writes down when it ran, with its arguments, and what `$next` handed back. */
class Trace
{
    /** @var list<string> */
    public static array $calls = [];

    public function handle(PendingActivity $activity, Closure $next, string ...$labels): mixed
    {
        $label = implode(',', $labels) ?: 'trace';

        self::$calls[] = "{$label}:before";

        $published = $next($activity);

        self::$calls[] = "{$label}:after:".($published instanceof Activity && $published->exists ? 'stored' : 'unsaved');

        return $published;
    }
}
