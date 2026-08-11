<?php

namespace Storyfeed\Support;

use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedLink;
use Throwable;

/**
 * Regenerate an entity's live link from its snapshot data — shared by the
 * payload presenter and the AS2.0 serializer so the two surfaces cannot
 * drift. One broken toFeedLink() never breaks a feed: failures are
 * reported and degrade to null.
 */
class LinkResolver
{
    public static function resolve(?string $type, array $data): ?FeedLink
    {
        if ($type === null) {
            return null;
        }

        $class = MorphResolver::classFor($type);

        if ($class === null || ! is_a($class, Feedable::class, true)) {
            return null;
        }

        try {
            return $class::toFeedLink($data);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }
}
