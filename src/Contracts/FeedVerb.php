<?php

namespace Storyfeed\Contracts;

use Storyfeed\ActivityStreams\ActivityType;

/**
 * A declared feed verb.
 *
 * Apps typically implement this on a backed string enum holding their verb
 * vocabulary, using the AsFeedVerb trait for defaults and builder access:
 *
 *   enum ActivityVerb: string implements FeedVerb
 *   {
 *       use AsFeedVerb;
 *
 *       case Comment = 'comment';
 *   }
 *
 * Verbs remain free-form strings in storage — this contract is an authoring
 * convenience, never a closed set.
 */
interface FeedVerb
{
    /**
     * The verb string as stored on the activity.
     */
    public function verb(): string;

    /**
     * The Activity Streams 2.0 activity type this verb maps to.
     *
     * Return null to express no opinion — the verb registry resolves it.
     * A non-enum string is permitted for extension types and is preserved
     * verbatim.
     */
    public function activityType(): ActivityType|string|null;
}
