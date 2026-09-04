<?php

namespace Storyfeed\ActivityStreams\Extension;

use Storyfeed\ActivityStreams\Context;

/**
 * The published Storyfeed context is add-only forever. Naming a term here
 * commits its meaning, so only the app-level verb belongs in this set.
 *
 * The backing value carries the prefix so emitting it cannot accidentally
 * revive the deprecated AS1 `verb`. This is not VocabularyTerm: that
 * contract is reserved for transcriptions of the W3C vocabulary.
 */
enum ExtensionTerm: string
{
    case Verb = 'sf:verb';

    public function iri(): string
    {
        return Context::SF_TERMS.substr($this->value, 3);
    }

    /**
     * Accept the same loose spellings as the AS2 enums, but only in our
     * namespace. A null result asks callers to preserve the original term;
     * it does not authorize dropping unfamiliar extension data.
     */
    public static function tryFromLoose(string $value): ?static
    {
        $value = trim($value);

        if (str_starts_with($value, Context::SF_TERMS)) {
            $value = substr($value, strlen(Context::SF_TERMS));
        } elseif (str_starts_with($value, 'sf:')) {
            $value = substr($value, 3);
        }

        foreach (self::cases() as $case) {
            if (strcasecmp(substr($case->value, 3), $value) === 0) {
                return $case;
            }
        }

        return null;
    }
}
