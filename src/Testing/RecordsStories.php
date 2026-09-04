<?php

namespace Storyfeed\Testing;

use Storyfeed\StoryfeedManager;

/**
 * Opt a test (or a whole file) back into writing the feed, in a suite that
 * muted recording through config.
 *
 * The recipe for a quiet suite is two lines: `STORYFEED_RECORDING_ENABLED=false`
 * in phpunit.xml, and this trait on the tests that assert on real feed rows.
 *
 *   uses(RecordsStories::class)->in('Feature/Feed');   // Pest
 *
 *   class FeedPageTest extends TestCase { use RecordsStories; }   // PHPUnit
 *
 * Nothing to tear down: the toggle lives on the manager singleton, and the
 * application is rebuilt for every test. Picked up through Laravel's own
 * `setUp{Trait}` convention, the way RefreshDatabase and WithFaker are.
 */
trait RecordsStories
{
    public function setUpRecordsStories(): void
    {
        app(StoryfeedManager::class)->startRecording();
    }
}
