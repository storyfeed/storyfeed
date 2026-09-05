<?php

namespace Storyfeed\Contracts;

use Storyfeed\ActivityStreams\ObjectType;

/**
 * Optional: lets a model declare its own Activity Streams 2.0 object type,
 * colocated with toFeed()/feedMedia() rather than in the central registry.
 *
 * Deliberately NOT part of Feedable — models that will never be serialized
 * to AS2.0 shouldn't have to care. The registry takes precedence, so an
 * application can always override a package's declaration.
 */
interface HasActivityStreamsType
{
    public static function activityStreamsType(): ObjectType|string;
}
