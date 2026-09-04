<?php

namespace Storyfeed\Tests\Testing;

use PHPUnit\Framework\Attributes\Test;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Testing\WithoutRecording;
use Storyfeed\Tests\TestCase;

/**
 * The other direction: a suite that records by default, and one class that
 * would rather not. A PHPUnit class for the same reason as RecordsStoriesTest.
 */
class WithoutRecordingTest extends TestCase
{
    use WithoutRecording;

    #[Test]
    public function it_mutes_a_class_in_a_suite_that_records_by_default(): void
    {
        $this->assertTrue(config('storyfeed.recording.enabled'));
        $this->assertFalse(Storyfeed::isRecording());
        $this->assertFalse(Storyfeed::activity('ping')->publish()->exists);
        $this->assertSame(0, Activity::query()->count());
    }

    #[Test]
    public function it_still_lets_the_fake_capture(): void
    {
        Storyfeed::fake();

        Storyfeed::activity('ping')->publish();

        Storyfeed::assertPublishedCount(1);
    }
}
