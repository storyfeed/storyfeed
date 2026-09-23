<?php

namespace Storyfeed\Exceptions;

use LogicException;

/**
 * Thrown when something names a Story that cannot be used.
 *
 * Loud on purpose. The alternative — publishing an activity with no verb, or
 * with a verb nothing authored a headline for — is a row in the feed that
 * renders a blank line, which is the failure class this whole layer exists to
 * make impossible.
 */
class UnknownStory extends LogicException
{
    public static function unregistered(string $story): self
    {
        return new self(
            "Story [{$story}] is not registered, so its verb and grammar were never compiled. "
            ."Bind it to its verb in routes/feed.php:\n\n"
            ."    Story::for(Order::class)->verb('ship', {$story}::class);\n\n"
            .'Publishing it anyway would record an activity nobody authored a headline for.'
        );
    }

    public static function notAStory(string $given): self
    {
        return new self(
            "[{$given}] is not a Storyfeed\\Stories\\Story subclass. PendingActivity::of() takes a Story class, and "
            .'the object comes after (->object($order)); to publish without a Story class, use '
            .'PendingActivity::inline($verb).'
        );
    }

    public static function classGivenAsObject(string $story, string $given): self
    {
        $short = class_basename($story);

        return new self(
            "{$short}::of() takes the activity's object, and was given the Story class [{$given}]. "
            ."Pass the model: {$short}::of(\$order). PendingActivity::of() is the one that takes a Story class."
        );
    }
}
