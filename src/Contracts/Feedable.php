<?php

namespace Storyfeed\Contracts;

use Storyfeed\FeedEntity;
use Storyfeed\FeedLink;

/**
 * Implement on any model that can appear in the feed as an actor, object,
 * target, or context.
 *
 * The two methods are the modular seam:
 *  - toFeed()     : a cacheable snapshot (label + data) written to the
 *                   snapshots table when the activity is published and
 *                   refreshed whenever the model is saved.
 *  - toFeedLink() : STATIC. Rebuilds a fresh link from the cached data at
 *                   read time, so cached labels stay fast but links never
 *                   go stale.
 */
interface Feedable
{
    /**
     * The feed entity representation of this model (label + cacheable data).
     */
    public function toFeed(): FeedEntity;

    /**
     * Generate a link for this entity, called at read time with cached data.
     * Return null for entities that are not independently linkable.
     *
     * @param  array  $data  Cached entity data from toFeed()->data
     */
    public static function toFeedLink(array $data): ?FeedLink;
}
