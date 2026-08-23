<?php

use Orchestra\Testbench\Attributes\WithConfig;
use Storyfeed\Demo\Vocabulary;
use Storyfeed\Facades\Storyfeed;

it('registers the demo grammar at boot when the app opts in', function () {
    // Rebooted with the flag on, because that is the only way to test this: the
    // vocabulary is registered in packageBooted(), so a runtime config()->set()
    // — the pattern the rest of the suite uses — would arrive after the decision
    // was made. Nothing here seeds anything, and that is the point: the bug this
    // pins is that the seeder runs in an artisan process while every process
    // that SHOWS the demo is a different one. Registered only by the seeder, a
    // seeded feed renders group nodes with null headlines in exactly the
    // surfaces the demo exists for — silently, and discovered on stage.
    static::usesTestingFeature(new WithConfig('storyfeed.demo.enabled', true));

    $this->refreshApplication();

    expect(Storyfeed::template(null, Vocabulary::UPLOAD))->not->toBeNull()
        ->and(Storyfeed::aggregateTemplateKey('repeat', Vocabulary::UPLOAD))->not->toBeNull()
        // Every axis that can win, not just the one a first run produced.
        ->and(Storyfeed::aggregateTemplateKey('object', Vocabulary::UPLOAD))->not->toBeNull()
        ->and(Storyfeed::aggregateTemplateKey('actors', Vocabulary::COMMENT))->not->toBeNull()
        ->and(Storyfeed::icon(null, Vocabulary::COMMENT))->not->toBeNull();
});
