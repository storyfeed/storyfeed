<?php

namespace Storyfeed\Stories;

use Closure;

/**
 * A name prefix for a group of definitions, returned by `Story::name()`, as
 * `Route::name('admin.')->group()` prefixes route names:
 *
 *     Story::name('billing.')->group(function () {
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

    /** Append to the prefix, as a route registrar's `name()` does. */
    public function name(string $prefix): self
    {
        $this->prefix .= $prefix;

        return $this;
    }

    public function group(Closure $callback): self
    {
        $this->registrar->withNamePrefix($this->prefix, $callback);

        return $this;
    }
}
