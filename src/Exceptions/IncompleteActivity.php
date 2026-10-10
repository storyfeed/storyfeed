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

    public static function featuredRoleEmpty(string $verb, string $role): self
    {
        $method = 'featuring'.ucfirst($role);

        return new self(
            "The [{$verb}] activity features its {$role}, which is empty. "
            ."Fill the {$role}, or drop {$method}() for this activity."
        );
    }
}
