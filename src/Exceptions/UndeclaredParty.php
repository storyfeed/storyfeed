<?php

namespace Storyfeed\Exceptions;

use InvalidArgumentException;

/**
 * Thrown in strict mode when an actor names a party `Storyfeed::parties()`
 * didn't declare.
 *
 * Like verbs.strict, a development-time assertion. In production the name
 * is ignored instead: the activity keeps the actor it would otherwise have
 * had, and `storyfeed:doctor` names the party.
 */
class UndeclaredParty extends InvalidArgumentException
{
    /** @param list<string> $declared */
    public static function make(string $name, string $where, array $declared): self
    {
        return new self(
            "{$where} names the party [{$name}], which Storyfeed::parties() does not declare (it declares "
            .($declared === [] ? 'none' : implode(', ', $declared)).'). Declare it in a service provider, '
            .'or disable storyfeed.parties.strict. In production an undeclared name is ignored, so a name '
            .'taken from a request can never create a party.'
        );
    }
}
