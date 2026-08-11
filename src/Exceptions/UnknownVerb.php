<?php

namespace Storyfeed\Exceptions;

use InvalidArgumentException;

/**
 * Thrown in strict mode when a verb resolves to no registry entry.
 *
 * Strict mode is a development-time assertion (local/testing by default),
 * never a storage constraint — verbs remain free-form in production.
 */
class UnknownVerb extends InvalidArgumentException
{
    public static function make(string $verb): self
    {
        return new self(
            "Storyfeed does not recognize the verb [{$verb}]. Register it with "
            ."Storyfeed::verbs(['{$verb}' => ActivityType::Update]) or an enum implementing FeedVerb, "
            .'or disable storyfeed.verbs.strict.'
        );
    }
}
