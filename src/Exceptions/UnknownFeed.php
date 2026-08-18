<?php

namespace Storyfeed\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a named feed preset does not exist.
 *
 * Loud on purpose, and here that is a safety property rather than a taste one.
 * A preset is how an app declares which verbs an audience may see; silently
 * falling back to the unfiltered feed when someone mistypes `custommer` would
 * turn a typo into a leak, and it would look exactly like a working feed.
 */
class UnknownFeed extends InvalidArgumentException
{
    /** @param list<string> $registered */
    public static function named(string $name, array $registered): self
    {
        $known = $registered === []
            ? 'No feeds are registered'
            : 'Registered feeds: '.implode(', ', $registered);

        return new self(
            "Feed [{$name}] is not registered. {$known}. Declare it in a service provider:\n\n"
            ."    Storyfeed::feeds([\n        '{$name}' => fn (FeedBuilder \$feed) => \$feed->only([...]),\n    ]);\n\n"
            .'Falling back to the unfiltered feed would answer a typo with every verb you have.'
        );
    }
}
