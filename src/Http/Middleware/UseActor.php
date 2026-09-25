<?php

namespace Storyfeed\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Storyfeed\StoryfeedManager;

class UseActor
{
    public function __construct(protected StoryfeedManager $storyfeed) {}

    public function handle(Request $request, Closure $next, string $party): mixed
    {
        return $this->storyfeed->actor($party, fn () => $next($request));
    }
}
