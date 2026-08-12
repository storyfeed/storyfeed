<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * An aggregate template referencing a token its axis does not pin renders a
 * lie: ":object" on the repeat axis produced "made 5 revisions to Aut
 * Beatae.docx" over children spanning five different documents. Registration
 * accepts anything; this is where it's caught.
 *
 * Story-authored templates are validated at COMPILE time by the same
 * derivation, so by the time this runs it is guarding the hand-written
 * registry path — the permanent escape hatch, which by definition has no
 * compiler in front of it.
 */
class AggregateTokens extends Check
{
    public function name(): string
    {
        return 'tokens';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        foreach ($storyfeed->registeredAggregateGrammar() as $key => $template) {
            if (! is_string($template)) {
                continue; // closures pre-render; nothing to inspect
            }

            $axis = explode('.', (string) $key, 2)[0];

            // Derived from the axis registry — a token is allowed iff the
            // axis's recipe makes it homogeneous; wildcards get the
            // intersection across all registered axes.
            $allowed = $storyfeed->aggregateTokens($axis);

            if ($allowed === null) {
                yield Finding::info(
                    'tokens.unregistered_axis',
                    "Note: aggregate grammar key `{$key}` references axis `{$axis}`, which is not registered — "
                    .'it will never resolve. Registered axes: '.implode(', ', array_keys($storyfeed->registeredAxes())).'.',
                    ['key' => (string) $key, 'axis' => $axis],
                );

                continue;
            }

            preg_match_all('/:[a-z]+/', $template, $matches);

            foreach (array_diff(array_unique($matches[0]), $allowed) as $token) {
                yield Finding::warning(
                    'tokens.unpinned',
                    "Aggregate template `{$key}` references `{$token}`, which "
                    .($axis === '*' ? 'not every axis pins' : "the {$axis} axis does not pin")
                    .' — groups on that axis may span many values, so the headline can lie. '
                    .'Allowed here: '.implode(' ', $allowed).'.',
                    ['key' => (string) $key, 'axis' => $axis, 'token' => $token],
                );
            }
        }
    }
}
