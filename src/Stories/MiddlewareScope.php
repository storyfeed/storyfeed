<?php

namespace Storyfeed\Stories;

use Closure;

/**
 * Middleware for a group of definitions, returned by `Story::middleware()`,
 * as `Route::middleware()` returns a RouteRegistrar:
 *
 *     Story::middleware(['audit'])->group(function () {
 *         Story::for(Invoice::class)->verb('pay')->headline(':actor paid :object');
 *         Story::verb('refund', InvoiceWasRefunded::class);
 *     });
 *
 * Each definition made inside the closure starts with this middleware,
 * ahead of its own. Groups nest, outermost first.
 */
final class MiddlewareScope
{
    /**
     * @param  list<string|Closure>  $middleware
     */
    public function __construct(
        private readonly Registrar $registrar,
        private array $middleware,
    ) {}

    /**
     * More middleware for the group.
     *
     * @param  string|list<string|Closure>|Closure  $middleware
     */
    public function middleware(string|array|Closure $middleware): self
    {
        $this->middleware = [...$this->middleware, ...Verb::middlewareList($middleware)];

        return $this;
    }

    public function group(Closure $callback): self
    {
        $this->registrar->withMiddleware($this->middleware, $callback);

        return $this;
    }
}
