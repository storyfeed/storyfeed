<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\SurfaceScanner;
use Storyfeed\Testing\StoryfeedFake;

/**
 * Feed surface that publishes nothing — THE detector for "the feed stopped
 * keeping up with the app".
 *
 * The reported failure this exists for: the grammar gets authored once, new
 * modules ship, and nothing tells you the feed has fallen behind. `freshness`
 * catches the crude version (nothing arriving at all). This catches the specific
 * version: something in the app has DECLARED itself part of the feed and never
 * appears in it.
 *
 * Only `Feedable` models and `PublishesToFeed` implementors count, because both
 * are explicit statements of intent — implementing Feedable says "this thing
 * belongs in the feed", so one with no grammar and no activity is a
 * contradiction rather than a guess. Plain app events are deliberately excluded:
 * every app has hundreds, and a heuristic list of "events that maybe should
 * publish" is noise that trains people to ignore the report.
 *
 * The honest limit, and it is not small: a module that never touches Storyfeed
 * at all is invisible to Storyfeed, and no check inside the package can see it.
 */
class UnwiredSurface extends Check
{
    public function __construct(
        protected SurfaceScanner $scanner,
    ) {}

    public function name(): string
    {
        return 'surface';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        $faked = $storyfeed instanceof StoryfeedFake;

        if (! $faked && ! $this->hasTable('activities')) {
            return;
        }

        $surface = $this->scanner->scan();

        // Fake-aware, like GrammarCoverage. Under Storyfeed::fake() nothing
        // reaches the table, so a database read would report every declared model
        // as never appearing — and this assertion's two siblings work fine in
        // faked tests, so someone reaching for all three together gets one
        // inexplicable failure. The inconsistency is the trap, not the strictness.
        $recordedTypes = $faked ? $storyfeed->recordedAliases() : $this->recordedAliases();

        // NON-VACUOUS GUARD. With an empty activities table every declared model
        // trivially "never appears", so the check would report the entire app as
        // broken while knowing nothing. That is not a strict reading — it is what
        // happened: run against a RefreshDatabase suite, it flagged all six of a
        // consumer's models, and the only way to satisfy it was to except all six,
        // which is a permanently vacuous assertion.
        //
        // Absence of evidence has to be reported as absence of evidence.
        if ($recordedTypes === []) {
            yield Finding::info(
                'surface.unassessable',
                'No activities are recorded, so declared feed surface cannot be assessed. Exercise the code that '
                .'publishes first, or run this against a database with real traffic.',
            );

            return;
        }

        foreach ($surface['feedable'] as $model) {
            $alias = (new $model)->getMorphClass();

            if (in_array($alias, $recordedTypes, true)) {
                continue;
            }

            if ($storyfeed->templateKey($alias, '*') !== null) {
                // Authored but not yet recorded — that is `dead`, not unwired,
                // and authoring ahead of traffic is a workflow we recommend.
                continue;
            }

            // Carefully worded: `Feedable` declares that a model APPEARS in the
            // feed, not that it publishes. Publishing from an Action class while
            // the model is merely a role is an ordinary Laravel shape, so the
            // finding is about the model never appearing in ANY role — which is a
            // real contradiction — and not about where the publish call lives.
            yield Finding::warning(
                'surface.unwired',
                "[{$model}] implements Feedable, declaring that it appears in the feed, but `{$alias}` has never "
                .'appeared in any role on any activity and no grammar is authored for it. Either something should '
                .'be publishing about it and nothing does, or the contract is left over from something removed.',
                ['model' => $model, 'alias' => $alias],
            );
        }

        // A PublishesToFeed implementor is a claim about publishing, not about
        // appearing — so this is the sound half of the check, and it needs no
        // guesswork about where the call site lives.
        foreach ($surface['publishers'] as $publisher) {
            yield Finding::info(
                'surface.publisher',
                "[{$publisher}] publishes to the feed.",
                ['publisher' => $publisher],
            );
        }
    }
}
