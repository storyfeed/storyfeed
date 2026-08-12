<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * Declared vocabulary vs recorded activity, in BOTH directions — the shape
 * that catches typos (recorded but never declared) and dead vocabulary
 * (declared but never recorded).
 *
 * This lives here rather than in `storyfeed:verbs` because both commands want
 * it and duplicating it would let the two answers drift. One implementation,
 * two views: `storyfeed:verbs --used` renders it as a drift report,
 * `storyfeed:doctor` folds it into the findings.
 *
 * "Dead vocabulary" is Info, not a warning: declaring a verb before it is used
 * is a legitimate thing to do (authoring ahead of traffic), and warning about
 * it would punish the workflow the docs recommend.
 */
class VerbDrift extends Check
{
    public function name(): string
    {
        return 'verbs';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if (! $this->hasTable('activities')) {
            yield Finding::info(
                'verbs.skipped',
                'No activities table — skipping the declared-vs-recorded comparison.',
            );

            return;
        }

        $known = array_keys($storyfeed->registeredVerbs());
        $recorded = $this->activities()->distinct()->pluck('verb')->all();

        // Has the app opted into a vocabulary at all? Verbs are free-form
        // strings by GUARANTEE, so an undeclared verb is only evidence of a
        // typo once the app has declared some vocabulary to deviate from.
        // Warning unconditionally would scold every app that uses the
        // documented zero-ceremony path.
        $opted = array_filter($known, fn (string $verb) => $storyfeed->declaredVerb($verb)) !== [];

        foreach (array_diff($recorded, $known) as $verb) {
            yield $opted
                ? Finding::warning(
                    'verbs.undeclared',
                    "Verb `{$verb}` is recorded but is not in the app's declared vocabulary — likely a typo. "
                    .'Undeclared verbs still record fine, but serialize as base `Activity`.',
                    ['verb' => $verb],
                )
                : Finding::info(
                    'verbs.undeclared',
                    "Verb `{$verb}` has no AS2.0 mapping — fine, but declaring a FeedVerb enum buys typo safety.",
                    ['verb' => $verb],
                );
        }

        // Only the app's OWN declarations can be dead. The 29 shipped defaults
        // being unused is normal and says nothing, and reporting them buried
        // the real signal under a screenful of noise.
        $declared = array_filter($known, fn (string $verb) => $storyfeed->declaredVerb($verb));

        foreach (array_diff($declared, $recorded) as $verb) {
            yield Finding::info(
                'verbs.dead',
                "Verb `{$verb}` is declared but never recorded (dead vocabulary, or authored ahead of traffic).",
                ['verb' => $verb],
            );
        }
    }
}
