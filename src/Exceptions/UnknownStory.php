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
            ."Add it in a service provider:\n\n"
            ."    Storyfeed::stories([\n        {$story}::class,\n    ]);\n\n"
            .'Publishing it anyway would record an activity nobody authored a headline for.'
        );
    }

    public static function notAStory(string $given): self
    {
        return new self(
            "[{$given}] is not a Storyfeed\\Story subclass. PendingStory::of() takes a Story; to publish "
            .'without one, use PendingStory::inline($verb).'
        );
    }
}
