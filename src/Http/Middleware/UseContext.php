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

    public function handle(Request $request, Closure $next, ?string $parameter = null): mixed
    {
        if ($parameter === null) {
            return $this->resolved($request, $next);
        }

        // Like Laravel's Authorize middleware, resolve the named route parameter.
        $context = $request->route($parameter);
        if (! $context instanceof Model) {
            throw new InvalidArgumentException("storyfeed.context: route parameter [{$parameter}] must be a bound Eloquent model.");
        }

        return $this->storyfeed->context($context, fn () => $next($request));
    }

    /**
     * No route parameter: ask the callback from `Storyfeed::resolveContextUsing()`.
     * Null from it runs the request without a context.
     */
    protected function resolved(Request $request, Closure $next): mixed
    {
        $resolver = $this->storyfeed->contextResolver();

        if ($resolver === null) {
            throw new InvalidArgumentException(
                'storyfeed.context: name a route parameter (`storyfeed.context:workspace`) or register a resolver with Storyfeed::resolveContextUsing().',
            );
        }

        $context = $resolver($request);

        return $context === null ? $next($request) : $this->storyfeed->context($context, fn () => $next($request));
    }
}
