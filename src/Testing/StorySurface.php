<?php

namespace Storyfeed\Testing;

use PHPUnit\Framework\Assert;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * Assertions about the app's feed SURFACE, so "the feed stopped keeping up" is
 * caught in CI rather than in a dashboard nobody opens.
 *
 * The distinction from GrammarCoverage: that asserts everything PUBLISHED is
 * authored. This asserts everything DECLARED actually publishes. A module can
 * pass the first perfectly by publishing nothing at all.
 */
class StorySurface
{
    /**
     * Assert no `Feedable` model exists that publishes nothing and has no
     * grammar authored.
     *
     * @param  array<int, class-string>  $except  surface that is legitimately silent
     */
    public static function assertNoUnwiredSurface(array $except = []): void
    {
        $report = app(StoryfeedManager::class)->doctor(['surface']);

        // REFUSE TO RUN ON NO EVIDENCE. Against an empty activities table every
        // declared model trivially never appears, so this would fail wholesale
        // while knowing nothing — and the only way to green it is to except
        // everything, which is a permanently vacuous assertion.
        //
        // That is not theoretical: it is what this assertion did to its first
        // consumer, who correctly deleted it rather than neuter it. Exercise the
        // code that publishes first, or point this at a seeded database.
        Assert::assertFalse(
            $report->has('surface.unassessable'),
            'No activities are recorded, so surface coverage proves nothing. Publish something first '
            .'(exercise the code under test), or run this against a seeded database.',
        );

        $findings = $report
            ->withCode('surface.unwired')
            ->reject(fn (Finding $finding) => in_array($finding->subject['model'] ?? null, $except, true));

        Assert::assertTrue(
            $findings->isEmpty(),
            "Feed surface is declared but never appears in the feed:\n  - "
            .$findings->map(fn (Finding $f) => (string) ($f->subject['model'] ?? '?'))->implode("\n  - ")
            ."\n\nEither something should be publishing about it, or pass it in \$except if it is deliberately "
            .'absent. Note `Feedable` means the model APPEARS in the feed — publishing from an Action class '
            ."elsewhere is fine, and satisfies this as soon as the model appears in any role.\n"
            .'(A module that never touches Storyfeed at all is invisible to Storyfeed — this only sees surface '
            .'that declared itself part of the feed.)',
        );
    }
}
