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
     * Storyfeed's extension context, defining `sf:verb` (the app-level verb)
     * and `sf:group` (curated group id).
     *
     * Note `sf:verb` is deliberately namespaced away from the DEPRECATED
     * AS1 `as:verb` property — they are not the same term.
     */
    public const SF = 'https://storyfeed.dev/ns';

    /** The context array emitted on every Storyfeed AS2.0 document. */
    public const DEFAULT = [self::AS2, self::SF];
}
