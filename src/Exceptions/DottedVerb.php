<?php

namespace Storyfeed\Exceptions;

use InvalidArgumentException;

class DottedVerb extends InvalidArgumentException
{
    public static function assertValid(string $verb): void
    {
        if (str_contains($verb, '.')) {
            throw new self("Verb [{$verb}] may not contain a dot; record the type as the object; use ->name() for dotted lookups.");
        }
    }
}
