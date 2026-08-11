<?php

namespace Storyfeed\Exceptions;

use LogicException;

/**
 * Thrown when publishing an activity that is missing something required.
 */
class IncompleteActivity extends LogicException
{
    public static function missingVerb(): self
    {
        return new self(
            'Cannot publish an activity without a verb. Pass one to '
            .'Storyfeed::activity($verb, $object) or call ->verb($verb).'
        );
    }
}
