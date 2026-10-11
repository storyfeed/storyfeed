<?php

namespace Storyfeed\Support;

use InvalidArgumentException;

/**
 * An app's own metadata on a batch or a tombstone: free-form JSON in the
 * row's `meta` column, written with `->meta([...])`. Top-level keys that
 * start with `storyfeed.` are core's, so an app never collides with a key
 * core adds later.
 *
 * @internal
 */
final class FreeformMeta
{
    public const RESERVED = 'storyfeed.';

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException naming a reserved key
     */
    public static function assert(array $meta): array
    {
        foreach (array_keys($meta) as $key) {
            if (str_starts_with((string) $key, self::RESERVED)) {
                throw new InvalidArgumentException(sprintf(
                    'The meta key [%s] starts with [%s], which Storyfeed reserves for itself. Name it without the prefix.', $key, self::RESERVED,
                ));
            }
        }

        return $meta;
    }

    /**
     * The stored meta with the new keys written over it, or the stored meta
     * unchanged when there are none.
     *
     * @param  array<array-key, mixed>|null  $stored
     * @param  array<string, mixed>  $meta
     * @return array<array-key, mixed>|null
     */
    public static function merge(?array $stored, array $meta): ?array
    {
        return $meta === [] ? $stored : array_replace($stored ?? [], $meta);
    }
}
