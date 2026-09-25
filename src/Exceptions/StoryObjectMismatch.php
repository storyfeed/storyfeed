<?php

namespace Storyfeed\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a named story is given an object of another type: the name
 * says `order`, the object's morph alias says `comment`. The analogue of
 * `UrlGenerationException::forMissingParameters()`
 * (Illuminate/Routing/Exceptions/UrlGenerationException.php), whose message
 * shape this copies: a route's parameters have to be what the route says.
 */
class StoryObjectMismatch extends InvalidArgumentException
{
    public static function forObject(string $name, string $key, string $given): self
    {
        return new self("Wrong object for [Story: {$name}] [Key: {$key}] [Given: {$given}].");
    }
}
