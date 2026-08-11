<?php

use PHPUnit\Framework\AssertionFailedError;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Testing\GrammarCoverage;
use Workbench\App\Models\Delivery;

it('passes when every pair has a headline and an icon', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object'])
        ->icons(['delivery.confirm' => 'bi-truck']);

    GrammarCoverage::assertCovers([['delivery', 'confirm']]);
});

it('fails and names what is missing', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object']);

    expect(fn () => GrammarCoverage::assertCovers([['delivery', 'confirm']]))
        ->toThrow(AssertionFailedError::class, 'delivery.confirm (no icon)');
});

it('does not accept a wildcard catch-all as coverage', function () {
    Storyfeed::grammar(['*.*' => ':actor acted'])->icons(['*.*' => 'bi-lightning']);

    // A *.* entry resolves for everything, which would make coverage vacuous.
    expect(fn () => GrammarCoverage::assertCovers([['delivery', 'confirm']]))
        ->toThrow(AssertionFailedError::class);

    GrammarCoverage::assertCovers([['delivery', 'confirm']], allowWildcard: true);
});

it('accepts a partial wildcard as deliberate authoring', function () {
    Storyfeed::grammar(['delivery.*' => ':actor did something to :object'])
        ->icons(['*.confirm' => 'bi-check']);

    GrammarCoverage::assertCovers([['delivery', 'confirm']]);
});

it('asserts coverage for everything the fake recorded', function () {
    Storyfeed::grammar([
        'delivery.confirm' => ':actor confirmed :object',
        'delivery.upload' => ':actor uploaded :object',
    ])->icons([
        'delivery.confirm' => 'bi-truck',
        'delivery.upload' => 'bi-upload',
    ]);

    Storyfeed::fake();

    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity('confirm', $delivery)->publish();
    Storyfeed::activity('upload', $delivery)->publish();

    GrammarCoverage::assertCoversRecorded();
});

it('catches an activity type nobody authored', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object'])
        ->icons(['delivery.confirm' => 'bi-truck']);

    Storyfeed::fake();

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();
    Storyfeed::activity('teleport', Delivery::create(['tracking_number' => 'TN-2']))->publish();

    expect(fn () => GrammarCoverage::assertCoversRecorded())
        ->toThrow(AssertionFailedError::class, 'delivery.teleport');
});

it('requires a fake for the recorded form', function () {
    expect(fn () => GrammarCoverage::assertCoversRecorded())
        ->toThrow(AssertionFailedError::class, 'requires Storyfeed::fake()');
});

it('refuses to pass vacuously when nothing was published', function () {
    Storyfeed::fake();

    expect(fn () => GrammarCoverage::assertCoversRecorded())
        ->toThrow(AssertionFailedError::class, 'proves nothing');
});

it('asserts coverage against persisted activities', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object'])
        ->icons(['delivery.confirm' => 'bi-truck']);

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    GrammarCoverage::assertCoversPublished();
});
