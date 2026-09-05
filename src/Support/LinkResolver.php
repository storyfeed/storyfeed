<?php

namespace Storyfeed\Support;

use Storyfeed\Contracts\Feedable;
use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;
use Throwable;

/**
 * Regenerate an entity's live media from its snapshot data — shared by the
 * payload presenter and the AS2.0 serializer so the two surfaces cannot
 * drift. One broken resolver never breaks a feed: failures are reported
 * and degrade to null.
 *
 * The seam survives the fold. Until 2026-09-05 this was where two resolver
 * contracts met — HasFeedMedia::feedMedia() winning over the older
 * Feedable::toFeedLink() — and it stays the single call site so that when
 * the contract grows both surfaces pick it up at once. It did, with issue
 * #4: FeedContext::model() arrived as a constructor argument that
 * each caller passes and this class never reads. Neither caller has ever
 * known which method answered, and neither needs to now that only one can.
 */
class LinkResolver
{
    public static function resolve(FeedContext $context): ?FeedMedia
    {
        $class = MorphResolver::classFor($context->type());

        if ($class === null || ! is_a($class, Feedable::class, true)) {
            return null;
        }

        try {
            return $class::feedMedia($context);
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }
}
