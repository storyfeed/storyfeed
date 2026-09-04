<?php

namespace Storyfeed\Support;

use Storyfeed\Contracts\Feedable;
use Storyfeed\Contracts\HasFeedMedia;
use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;
use Throwable;

/**
 * Regenerate an entity's live media from its snapshot data — shared by the
 * payload presenter and the AS2.0 serializer so the two surfaces cannot
 * drift. One broken resolver never breaks a feed: failures are reported
 * and degrade to null.
 *
 * This is also the single point where the two resolver contracts meet.
 * HasFeedMedia::feedMedia() wins when a class implements it; otherwise
 * Feedable::toFeedLink() answers, indefinitely. Both call sites see only a
 * FeedMedia, so neither knows which contract spoke.
 */
class LinkResolver
{
    public static function resolve(FeedContext $context): ?FeedMedia
    {
        $class = MorphResolver::classFor($context->type());

        if ($class === null) {
            return null;
        }

        try {
            if (is_a($class, HasFeedMedia::class, true)) {
                return $class::feedMedia($context);
            }

            if (is_a($class, Feedable::class, true)) {
                $link = $class::toFeedLink($context->data());

                return $link === null ? null : FeedMedia::fromLink($link);
            }
        } catch (Throwable $e) {
            report($e);
        }

        return null;
    }
}
