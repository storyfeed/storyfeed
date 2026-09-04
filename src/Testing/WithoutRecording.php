<?php

namespace Storyfeed\Testing;

use Storyfeed\StoryfeedManager;

/**
 * Mute the feed for a test (or a whole file), in a suite that records by
 * default — the other direction from RecordsStories.
 *
 *   uses(WithoutRecording::class)->in('Feature/Billing');   // Pest
 *
 * Every publish() inside composes its Activity and returns it unsaved, and
 * Feedable models stop refreshing snapshots on save. `Storyfeed::fake()` still
 * captures — an explicit fake outranks the switch — so a test that wants
 * assertions instead of rows keeps working as it always did.
 *
 * Named after Laravel's WithoutMiddleware, and after the closure it wraps:
 * `Storyfeed::withoutRecording(fn () => …)` is the same thing for one call.
 */
trait WithoutRecording
{
    public function setUpWithoutRecording(): void
    {
        app(StoryfeedManager::class)->stopRecording();
    }
}
