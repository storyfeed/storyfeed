<?php

namespace Storyfeed\ActivityStreams\Concerns;

use Storyfeed\ActivityStreams\Context;

/**
 * Shared behaviour for the Activity Streams 2.0 vocabulary enums.
 */
trait IsVocabularyTerm
{
    public function iri(): string
    {
        return Context::AS2.'#'.$this->value;
    }

    /**
     * Resolve a term from any of the forms seen in the wild:
     * `Create`, `create`, `as:Create`, or the full IRI.
     *
     * Returns null for unrecognized terms — callers MUST preserve the raw
     * string rather than discarding it. Dropping unknown types is the
     * data-loss bug that breaks federation interoperability.
     */
    public static function tryFromLoose(string $value): ?static
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        if (str_starts_with($value, Context::AS2.'#')) {
            $value = substr($value, strlen(Context::AS2) + 1);
        } elseif (str_starts_with($value, 'as:')) {
            $value = substr($value, 3);
        }

        foreach (static::cases() as $case) {
            if (strcasecmp($case->value, $value) === 0) {
                return $case;
            }
        }

        return null;
    }
}
