<?php

namespace Storyfeed\Tests\Testing;

use PHPUnit\Framework\Attributes\Test;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Testing\RecordsStories;
use Storyfeed\Tests\TestCase;

/**
 * The quiet-suite recipe, from the consumer's side: the env var mutes the
 * whole suite (simulated in getEnvironmentSetUp, which runs where phpunit.xml's
 * `<env>` would be read), and the trait opts one class back in. A PHPUnit
 * class rather than a Pest file so the trait is picked up exactly as a
 * consumer's `use RecordsStories;` is — through Laravel's setUp{Trait} hook.
 */
class RecordsStoriesTest extends TestCase
{
    use RecordsStories;

    public function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('storyfeed.recording.enabled', false);
    }

    #[Test]
    public function it_records_in_a_suite_muted_through_config(): void
    {
        $this->assertFalse(config('storyfeed.recording.enabled'));
        $this->assertTrue(Storyfeed::isRecording());
        $this->assertTrue(Storyfeed::activity('ping')->publish()->exists);
    }
}
