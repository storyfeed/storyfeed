<?php

namespace Storyfeed\Support;

use Closure;

/**
 * Story middleware names to what the pipeline runs, as the router's
 * `Illuminate\Routing\MiddlewareNameResolver` does for routes: an alias
 * becomes its class with any `:arguments` kept, a group becomes its members
 * (groups may name groups), and anything else passes through as written.
 *
 * @internal
 */
final class MiddlewareNameResolver
{
    /**
     * @param  array<string, string|Closure>  $aliases
     * @param  array<string, list<string|Closure>>  $groups
     * @param  array<string, true>  $seen  groups being expanded, so a group naming itself stops
     * @return list<string|Closure>
     */
    public static function resolve(string|Closure $name, array $aliases, array $groups, array $seen = []): array
    {
        if ($name instanceof Closure) {
            return [$name];
        }

        if (isset($aliases[$name]) && $aliases[$name] instanceof Closure) {
            return [$aliases[$name]];
        }

        if (isset($groups[$name]) && ! isset($seen[$name])) {
            $resolved = [];

            foreach ($groups[$name] as $member) {
                array_push($resolved, ...self::resolve($member, $aliases, $groups, [...$seen, $name => true]));
            }

            return $resolved;
        }

        [$alias, $arguments] = array_pad(explode(':', $name, 2), 2, null);

        $class = $aliases[$alias] ?? $alias;

        if ($class instanceof Closure) {
            return [$class];
        }

        return [$class.($arguments === null ? '' : ':'.$arguments)];
    }

    /**
     * The middleware to run: each name resolved, the exclusions taken out,
     * and identical strings kept once, in first-seen order. An exclusion
     * matches the resolved string exactly, as the router's does, so
     * excluding `batch` leaves `batch:5 minutes` in place.
     *
     * @param  list<string|Closure>  $middleware
     * @param  list<string>  $excluded
     * @param  array<string, string|Closure>  $aliases
     * @param  array<string, list<string|Closure>>  $groups
     * @return list<string|Closure>
     */
    public static function gather(array $middleware, array $excluded, array $aliases, array $groups): array
    {
        $without = [];

        foreach ($excluded as $name) {
            foreach (self::resolve($name, $aliases, $groups) as $resolved) {
                if (is_string($resolved)) {
                    $without[$resolved] = true;
                }
            }
        }

        $gathered = [];
        $seen = [];

        foreach ($middleware as $name) {
            foreach (self::resolve($name, $aliases, $groups) as $resolved) {
                if (is_string($resolved)) {
                    if (isset($without[$resolved]) || isset($seen[$resolved])) {
                        continue;
                    }

                    $seen[$resolved] = true;
                }

                $gathered[] = $resolved;
            }
        }

        return $gathered;
    }
}
