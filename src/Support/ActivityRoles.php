<?php

namespace Storyfeed\Support;

/** @internal Fixed role selections; storage additions do not extend renderer contracts. */
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
