<?php

namespace Storyfeed\Support;

/**
 * A group node's `id`: its axis and grouping hash, encoded so the id alone
 * names the group again — `FeedBuilder::members()` takes it back. Opaque to
 * consumers like the cursor; only its stability is contract.
 *
 * @internal
 */
final class GroupId
{
    private const PREFIX = 'grp_';

    private const VERSION = 'v2';

    public static function encode(string $axis, string $hash): string
    {
        return self::PREFIX.rtrim(strtr(base64_encode(self::VERSION."\x1f{$axis}\x1f{$hash}"), '+/', '-_'), '=');
    }

    /** @return array{axis: string, hash: string}|null null when the string is not a group id */
    public static function decode(string $id): ?array
    {
        if (! str_starts_with($id, self::PREFIX)) {
            return null;
        }

        $decoded = base64_decode(strtr(substr($id, strlen(self::PREFIX)), '-_', '+/'), true);
        $parts = $decoded === false ? [] : explode("\x1f", $decoded, 3);

        if (count($parts) !== 3 || $parts[0] !== self::VERSION || $parts[1] === '' || $parts[2] === '') {
            return null;
        }

        return ['axis' => $parts[1], 'hash' => $parts[2]];
    }
}
