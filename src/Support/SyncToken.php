<?php

namespace Storyfeed\Support;

use Illuminate\Support\Str;
use Storyfeed\Models\Meta;
use Throwable;

/**
 * The feed's resync signal — an OPAQUE token in the payload envelope,
 * sibling to the cursor. Clients store it and compare: when a later page's
 * token differs, all accumulated nodes are suspect (settled history was
 * rewritten) — drop them and refetch. Never interpreted client-side.
 *
 * Bumped ONLY by settled-history rewrites (storyfeed:bundle,
 * storyfeed:curate --rehash) so it stays a rare, meaningful signal. Live
 * regrouping — including automatic composite minting — is deliberately
 * excluded: it lands near the head page, where the published client
 * reconciliation rules already work (docs/payload.md).
 *
 * Internals (not contract): a ULID, which embeds a timestamp so an
 * operator peeking at feed_meta can still read "when" by eye.
 */
class SyncToken
{
    protected const KEY = 'sync_token';

    /**
     * Null when never bumped, and null when the meta table does not exist
     * yet (an adopter mid-upgrade) — the feed degrades, never breaks.
     */
    public static function current(): ?string
    {
        try {
            return Meta::query()->where('key', self::KEY)->value('value');
        } catch (Throwable) {
            return null;
        }
    }

    public static function bump(): string
    {
        $token = (string) Str::ulid();

        Meta::query()->updateOrCreate(['key' => self::KEY], ['value' => $token]);

        return $token;
    }
}
