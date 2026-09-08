<?php

namespace Storyfeed\Support;

/**
 * Supported, fixed role selections for consumers of Storyfeed activities.
 *
 * STORED lists every role with persisted {role}_type / {role}_id columns.
 * Walk it when comparing two activities' role identity, comparing both values
 * for each role (including nulls), without resolving the related models.
 * PAYLOAD lists roles exposed to renderers as entity objects when present;
 * membership does not guarantee a key or a non-null entity on every node.
 * GROUPABLE lists role names that an axis recipe may use.
 *
 * The three lists are IDENTICAL as of W86, and stay three lists anyway. They
 * exist so that adding a column can never, by itself, add a payload key or a
 * grouping axis — the leak that separate lists make impossible and one list
 * makes invisible. That reason survives them agreeing; the next storage-only
 * role is what they are for. This separation is a public promise: choose the
 * list for your boundary, never assume the three remain equal.
 *
 * Each constant is a list<string> that callers may read, iterate, and compare
 * by membership. Order, numeric positions, and a fixed size are not contract.
 * Roles may join any list in a backward-compatible release; consumers must
 * tolerate additions rather than depend on exact array equality. Removing or
 * renaming a member of any list is a breaking change, even if it remains in
 * another list, and requires deprecation and an announced breaking release.
 *
 * These lists and cachedRelations() are public API, not configuration or an
 * extension point. The class remains final and its selections are not overridable.
 */
final class ActivityRoles
{
    public const STORED = ['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'];

    public const PAYLOAD = ['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'];

    public const GROUPABLE = ['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'];

    /**
     * Supported eager-loading names for the cached entities of all STORED roles.
     *
     * Pass to Activity::with() or an activity's loadMissing(). These are cached
     * snapshot relations, not the live polymorphic role relations. Publishing
     * this helper keeps consumers from reconstructing the naming convention.
     * The result follows STORED membership, including future additions; order
     * is not contract. Removing or renaming a returned relation is a breaking
     * change requiring deprecation and an announced breaking release.
     *
     * @return list<string>
     */
    public static function cachedRelations(): array
    {
        return array_map(fn (string $role) => 'cached'.ucfirst($role), self::STORED);
    }
}
