<?php

namespace Storyfeed\Support;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Storyfeed\Models\Activity;
use Stringable;

/**
 * Named literal values for a headline: `':actor sent the invoice to
 * :recipient'` with `->bind('recipient', $name)`.
 *
 * A bound value is text, exactly as given. It never enters a template, so
 * nothing reads it again: `[x]` is not an optional segment, `:object` is not
 * a token, and `<b>` is not markup. The template keeps `:recipient` and the
 * payload carries the value beside it, in `bindings`.
 *
 * @internal
 */
final class Bindings
{
    /** Tokens a binding may not take: they already mean something. */
    public const array RESERVED = ['count', 'others', 'verb'];

    /**
     * @throws InvalidArgumentException for a name that is not a token, or
     *                                  one that names a role or a core token
     */
    public static function name(string $name): string
    {
        if (preg_match('/^[a-z]+$/', $name) !== 1) {
            throw new InvalidArgumentException("The binding name [{$name}] is not a token. Use lowercase letters only, as in :recipient.");
        }

        $singular = str_ends_with($name, 's') ? substr($name, 0, -1) : $name;

        if (in_array($name, ActivityRoles::PAYLOAD, true) || in_array($singular, ActivityRoles::PAYLOAD, true) || in_array($name, self::RESERVED, true)) {
            throw new InvalidArgumentException("The binding name [{$name}] is already a headline token (:{$name}). Choose another name.");
        }

        return $name;
    }

    public static function value(string|int|float|Stringable $value): string
    {
        return (string) $value;
    }

    /**
     * An activity's stored bindings, or an empty map.
     *
     * @return array<string, string>
     */
    public static function of(Activity $activity): array
    {
        $stored = $activity->getAttribute('bindings');

        if (! is_array($stored)) {
            return [];
        }

        $bindings = [];

        foreach ($stored as $name => $value) {
            if (is_string($name) && (is_string($value) || is_int($value) || is_float($value))) {
                $bindings[$name] = (string) $value;
            }
        }

        return $bindings;
    }

    /**
     * The bindings every member holds with the same value: what a group can
     * say for all of them.
     *
     * @param  Collection<int, Activity>  $members
     * @return array<string, string>
     */
    public static function common(Collection $members): array
    {
        return self::intersect($members->map(fn (Activity $member): array => self::of($member)));
    }

    /**
     * The name => text pairs every map holds alike.
     *
     * @param  iterable<array<string, string>>  $maps
     * @return array<string, string>
     */
    public static function intersect(iterable $maps): array
    {
        $common = null;

        foreach ($maps as $bindings) {
            $common = $common === null ? $bindings : array_intersect_assoc($common, $bindings);

            if ($common === []) {
                return [];
            }
        }

        return $common ?? [];
    }

    /**
     * Put bound values into finished text in one pass over the text, never
     * over its output. Unbound tokens are left as they are.
     *
     * @param  array<string, string>  $bindings
     */
    public static function fill(string $text, array $bindings): string
    {
        if ($bindings === []) {
            return $text;
        }

        return (string) preg_replace_callback(
            '/:([a-z]+)/',
            fn (array $match): string => $bindings[$match[1]] ?? $match[0],
            $text,
        );
    }

    /**
     * Whether the text names a bound token.
     *
     * @param  array<string, string>  $bindings
     */
    public static function named(string $text, array $bindings): bool
    {
        preg_match_all('/:([a-z]+)/', $text, $matches);

        return array_intersect($matches[1], array_keys($bindings)) !== [];
    }
}
