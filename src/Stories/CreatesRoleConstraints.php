<?php

namespace Storyfeed\Stories;

/**
 * The named role constraints, each a `whereRole()` for one role, as
 * `CreatesRegularExpressionRouteConstraints` gives Route, RouteRegistrar
 * and PendingResourceRegistration their `whereNumber()` and the rest, each
 * a `where()` (Illuminate/Routing/CreatesRegularExpressionRouteConstraints.php).
 *
 *     Story::for(Order::class)->verb('refund')->whereActor(User::class, 'party');
 *
 * Origin, result and instrument have no shorthand; name them:
 * `->whereRole('origin', Warehouse::class)`.
 */
trait CreatesRoleConstraints
{
    /**
     * @param  string|list<string>  ...$types  model classes, morph aliases, or `'party'`
     */
    public function whereActor(string|array ...$types): static
    {
        return $this->whereRole('actor', ...$types);
    }

    /**
     * @param  string|list<string>  ...$types  model classes, morph aliases, or `'party'`
     */
    public function whereObject(string|array ...$types): static
    {
        return $this->whereRole('object', ...$types);
    }

    /**
     * @param  string|list<string>  ...$types  model classes, morph aliases, or `'party'`
     */
    public function whereTarget(string|array ...$types): static
    {
        return $this->whereRole('target', ...$types);
    }

    /**
     * @param  string|list<string>  ...$types  model classes, morph aliases, or `'party'`
     */
    public function whereContext(string|array ...$types): static
    {
        return $this->whereRole('context', ...$types);
    }

    /**
     * @param  string|list<string>  ...$types  model classes, morph aliases, or `'party'`
     */
    abstract public function whereRole(string $role, string|array ...$types): static;
}
