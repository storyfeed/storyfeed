<?php

namespace Storyfeed\Exceptions;

use LogicException;

/**
 * Thrown when a fluent value is used — serialised or stored — before a value
 * it cannot do without was set.
 *
 * Every fluent class's `make()` can be called empty, so the check cannot live
 * there. It lives where the value is first needed, and the message names the
 * method that supplies it, because that is the line the reader has to write.
 */
class IncompleteFeedValue extends LogicException
{
    /**
     * @param  class-string  $class
     */
    public static function missing(string $class, string $method): self
    {
        $name = class_basename($class);

        return new self(
            "{$name} has no {$method}. Call ->{$method}(…) on it, "
            ."or pass {$method}: to {$name}::make()."
        );
    }
}
