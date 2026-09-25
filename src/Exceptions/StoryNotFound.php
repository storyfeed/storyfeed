<?php

namespace Storyfeed\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when `story()`, `Storyfeed::route()` or a `storyIs()` pattern's
 * lookup names a story nothing defined, as `route()` throws
 * `RouteNotFoundException` (Illuminate/Routing/UrlGenerator.php). Always:
 * a name is strict by construction, whatever `storyfeed.verbs.strict` says,
 * because a route name has no off switch either.
 */
class StoryNotFound extends InvalidArgumentException
{
    public static function named(string $name): self
    {
        return new self("Story [{$name}] not defined.");
    }
}
