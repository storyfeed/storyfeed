<?php

namespace Storyfeed\Contracts;

use Storyfeed\FeedContext;
use Storyfeed\FeedMedia;

/**
 * Optional: the successor to Feedable::toFeedLink(), receiving a context
 * object instead of the bare snapshot array so the contract can gain
 * information (the feed being read, a hydrated model) without breaking
 * every implementation.
 *
 * Deliberately NOT folded into Feedable yet — that is a break, budgeted for
 * v1. Until then the read path checks for this interface first and falls
 * back to toFeedLink(), so an application migrates one model at a time. A
 * class implementing both is answered by this method alone.
 *
 * The `to` prefix is gone on purpose: toFeed() converts $this, while this
 * method is static and converts nothing belonging to an instance.
 *
 * Same rules as toFeedLink(): called at read time, only for entities that
 * have a snapshot, and a throw is reported and degrades the entity to
 * url: null. Return null for entities that are not independently linkable.
 */
interface HasFeedMedia
{
    public static function feedMedia(FeedContext $context): ?FeedMedia;
}
