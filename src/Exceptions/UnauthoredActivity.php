<?php

namespace Storyfeed\Exceptions;

use LogicException;

/**
 * Thrown in strict-grammar mode when publishing a (type, verb) that has no
 * headline authored for it.
 *
 * Strict grammar is a development-time assertion (local/testing by default),
 * never a storage constraint — production always publishes, and a missing
 * headline degrades to null exactly as before.
 *
 * It exists for one failure: on real apps the grammar gets authored once and
 * then never grows, so new modules ship publishing activities nobody wrote a
 * sentence for. Nothing reports that, because a null headline is a blank line
 * rather than an error. This turns "author it later" into an exception at the
 * moment the publish call is written.
 */
class UnauthoredActivity extends LogicException
{
    public static function make(?string $type, string $verb): self
    {
        $label = ($type ?? '(no object)').'.'.$verb;
        $key = ($type ?? '*').'.'.$verb;

        return new self(
            "Publishing [{$label}] but no headline is authored for it — the feed would render a blank line. "
            ."Author it as a Story, or register it directly:\n\n"
            ."    Storyfeed::grammar(['{$key}' => ':actor …']);\n\n"
            .'Resist `*.*`: a catch-all silences every future gap and makes coverage reports meaningless. '
            .'Set storyfeed.grammar.strict = false to disable this assertion.'
        );
    }
}
