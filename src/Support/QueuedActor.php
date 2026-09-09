<?php

namespace Storyfeed\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Log\Context\Repository;
use Illuminate\Support\Facades\Auth;

/** Scalar identity only: no model restoration and no identity in log context. */
class QueuedActor
{
    public const string KEY = 'storyfeed.internal.actor';

    public static function capture(Repository $context): void
    {
        // Dehydration receives a copy, so dispatch never changes request context.
        // Preserve inherited identity for chained jobs. A hidden null opts out.
        if ($context->hasHidden(self::KEY)) {
            return;
        }

        $actor = Auth::user();
        if ($actor instanceof Model && $actor->getKey() !== null) {
            $context->addHidden(self::KEY, [
                'type' => $actor->getMorphClass(),
                'id' => $actor->getKey(),
            ]);
        }
    }
}
