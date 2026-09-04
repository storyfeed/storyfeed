<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;

/**
 * Is the feed being written at all?
 *
 * The recording switch exists for test suites. Left off anywhere else it is
 * the quietest failure this package has: every publish() returns an unsaved
 * row and no exception, every feature keeps working, and the feed simply
 * stops the day the env var was copied into the wrong file. Every other
 * check asks whether what was recorded is correct; this one asks whether
 * anything is being recorded.
 *
 * Info rather than a warning under `testing`, where muting is the documented
 * recipe — but still said out loud, because a suite whose feed assertions
 * all pass against zero rows is the vacuous pass in another coat.
 *
 * Honest about its reach: a runtime stopRecording() in a web request is
 * invisible to a console doctor run. What this sees is config, plus anything
 * a boot-time call did to this process.
 */
class Recording extends Check
{
    public function name(): string
    {
        return 'recording';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        if ($storyfeed->isRecording()) {
            return;
        }

        $environment = (string) app()->environment();

        $subject = [
            'environment' => $environment,
            'config' => (bool) config('storyfeed.recording.enabled', true),
        ];

        if (app()->environment('testing')) {
            yield Finding::info(
                'recording.disabled',
                'Recording is switched off in this process — the quiet-suite recipe. Tests that assert on '
                .'feed rows need `Storyfeed\Testing\RecordsStories` or Storyfeed::startRecording(), or they '
                .'pass against nothing.',
                $subject,
            );

            return;
        }

        yield Finding::warning(
            'recording.disabled',
            "Recording is switched off in `{$environment}` (`storyfeed.recording.enabled` is false, or "
            .'something called Storyfeed::stopRecording() at boot). Every publish() is returning an unsaved '
            .'Activity, no ActivityPublished is dispatched, and Feedable models are not refreshing their '
            .'snapshots: the feed is frozen at whatever it already holds, and nothing else will say so. '
            .'`STORYFEED_RECORDING_ENABLED=false` belongs in phpunit.xml, not in .env.',
            $subject,
        );
    }
}
