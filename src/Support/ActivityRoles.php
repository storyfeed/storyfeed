<?php

namespace Storyfeed\Support;

/**
 * @internal Fixed role selections.
 *
 * The three lists are IDENTICAL as of W86, and stay three lists anyway. They
 * exist so that adding a column can never, by itself, add a payload key or a
 * grouping axis — the leak that separate lists make impossible and one list
 * makes invisible. That reason survives them agreeing; the next storage-only
 * role is what they are for. Do not collapse them.
 */
final class ActivityRoles
{
    public const STORED = ['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'];

    public const PAYLOAD = ['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'];

    public const GROUPABLE = ['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'];

    /** @return list<string> */
    public static function cachedRelations(): array
    {
        return array_map(fn (string $role) => 'cached'.ucfirst($role), self::STORED);
    }
}
