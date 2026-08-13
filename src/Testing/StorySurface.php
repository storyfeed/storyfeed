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
        $findings = app(StoryfeedManager::class)
            ->doctor(['surface'])
            ->withCode('surface.unwired')
            ->reject(fn (Finding $finding) => in_array($finding->subject['model'] ?? null, $except, true));

        Assert::assertTrue(
            $findings->isEmpty(),
            "Feed surface is declared but publishes nothing:\n  - "
            .$findings->map(fn (Finding $f) => (string) ($f->subject['model'] ?? '?'))->implode("\n  - ")
            ."\n\nEither wire it up, or pass it in \$except if it is deliberately silent. "
            .'(A module that never touches Storyfeed at all is invisible to Storyfeed — this only sees '
            .'surface that declared itself part of the feed.)',
        );
    }
}
