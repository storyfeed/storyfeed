<?php

namespace Storyfeed\Stories;

use Closure;

/**
 * A name prefix for a group of definitions, returned by `Story::as()`, as
 * `Route::as('admin.')->group()` prefixes route names:
 *
 *     Story::as('billing.')->group(function () {
 *         Story::for(Invoice::class)->verb('send')->name('invoice.sent');   // billing.invoice.sent
 *         Story::resource(Refund::class);                                   // billing.refund.create, …
 *     });
 *
 * Only a name is prefixed: a verb inside the group that isn't named stays
 * unnamed. Groups nest, outermost first.
 */
final class NameScope
{
    public function __construct(
        private readonly Registrar $registrar,
        private string $prefix,
    ) {}

    /** Append to the prefix, as a route registrar's `as()` does. */
    public function as(string $prefix): self
    {
        $this->prefix .= $prefix;

        return $this;
    }

    /** Alias for as(), following Laravel's name-to-as route attribute alias. */
    public function name(string $prefix): self
    {
        return $this->as($prefix);
    }

    public function group(Closure $callback): self
    {
        $this->registrar->withNamePrefix($this->prefix, $callback);

        return $this;
    }
}
