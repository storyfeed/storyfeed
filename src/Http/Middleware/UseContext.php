<?php

namespace Storyfeed\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Storyfeed\StoryfeedManager;

class UseContext
{
    public function __construct(protected StoryfeedManager $storyfeed) {}

    public function handle(Request $request, Closure $next, string $parameter): mixed
    {
        // Like Laravel's Authorize middleware, resolve the named route parameter.
        $context = $request->route($parameter);
        if (! $context instanceof Model) {
            throw new InvalidArgumentException("storyfeed.context: route parameter [{$parameter}] must be a bound Eloquent model.");
        }

        return $this->storyfeed->context($context, fn () => $next($request));
    }
}
