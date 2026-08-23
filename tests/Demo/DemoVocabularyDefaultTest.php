<?php

use Storyfeed\Demo\Vocabulary;
use Storyfeed\Facades\Storyfeed;

it('leaves an app that never asked for a demo entirely alone', function () {
    // Off by default: these verbs in an application's registry are real noise in
    // doctor's feed coverage, and an app that never runs a demo should not have
    // to explain them.
    expect(Storyfeed::template(null, Vocabulary::UPLOAD))->toBeNull()
        ->and(Storyfeed::declaredVerb(Vocabulary::UPLOAD))->toBeFalse();
});
