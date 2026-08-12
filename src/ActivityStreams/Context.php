<?php

namespace Storyfeed\ActivityStreams;

/**
 * JSON-LD context URLs. Constants rather than an enum — these are URLs, not
 * a closed vocabulary.
 */
final class Context
{
    /** The normative Activity Streams 2.0 context. */
    public const AS2 = 'https://www.w3.org/ns/activitystreams';

    /**
     * Storyfeed's extension context, defining `sf:verb` (the app-level verb).
     *
     * Note `sf:verb` is deliberately namespaced away from the DEPRECATED
     * AS1 `as:verb` property — they are not the same term.
     *
     * A DEDICATED HOST, not a path on the marketing site: every emitted
     * document references this URL, so it outlives any site replatform. On
     * `storyfeed.dev/ns` the marketing site's host would be load-bearing for
     * machine consumers forever, and its router could shadow the path. This
     * is the same discipline as the frozen migration create stubs (journal
     * 023), enforced by DNS instead of by remembering. Unversioned on
     * purpose — the context is add-only forever, exactly like the payload
     * contract, so there is never a v2 to bump to.
     */
    public const SF = 'https://ns.storyfeed.dev';

    /** Base IRI for `sf:` terms, defined by the context document itself. */
    public const SF_TERMS = self::SF.'#';

    /** The context array emitted on every Storyfeed AS2.0 document. */
    public const DEFAULT = [self::AS2, self::SF];
}
