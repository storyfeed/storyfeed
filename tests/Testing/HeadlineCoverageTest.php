<?php

use PHPUnit\Framework\AssertionFailedError;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Testing\GrammarCoverage;
use Storyfeed\Testing\HeadlineCoverage;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('passes when every pair has a headline and an icon', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object'])
        ->icons(['delivery.confirm' => 'bi-truck']);

    HeadlineCoverage::assertCovers([['delivery', 'confirm']]);
});

it('fails and names what is missing', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object']);

    expect(fn () => HeadlineCoverage::assertCovers([['delivery', 'confirm']]))
        ->toThrow(AssertionFailedError::class, 'delivery.confirm (no icon)');
});

it('does not accept a wildcard catch-all as coverage', function () {
    Storyfeed::grammar(['*.*' => ':actor acted'])->icons(['*.*' => 'bi-lightning']);

    // A *.* entry resolves for everything, which would make coverage vacuous.
    expect(fn () => HeadlineCoverage::assertCovers([['delivery', 'confirm']]))
        ->toThrow(AssertionFailedError::class);

    HeadlineCoverage::assertCovers([['delivery', 'confirm']], allowWildcard: true);
});

it('accepts a partial wildcard as deliberate authoring', function () {
    Storyfeed::grammar(['delivery.*' => ':actor did something to :object'])
        ->icons(['*.confirm' => 'bi-check']);

    HeadlineCoverage::assertCovers([['delivery', 'confirm']]);
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

    HeadlineCoverage::assertCoversRecorded();
});

it('catches an activity type nobody authored', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object'])
        ->icons(['delivery.confirm' => 'bi-truck']);

    Storyfeed::fake();

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();
    Storyfeed::activity('teleport', Delivery::create(['tracking_number' => 'TN-2']))->publish();

    expect(fn () => HeadlineCoverage::assertCoversRecorded())
        ->toThrow(AssertionFailedError::class, 'delivery.teleport');
});

it('requires a fake for the recorded form', function () {
    expect(fn () => HeadlineCoverage::assertCoversRecorded())
        ->toThrow(AssertionFailedError::class, 'requires Storyfeed::fake()');
});

it('refuses to pass vacuously when nothing was published', function () {
    Storyfeed::fake();

    expect(fn () => HeadlineCoverage::assertCoversRecorded())
        ->toThrow(AssertionFailedError::class, 'proves nothing');
});

it('asserts coverage against persisted activities', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object'])
        ->icons(['delivery.confirm' => 'bi-truck']);

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    HeadlineCoverage::assertCoversPublished();
});

it('asserts aggregate grammar for the axes curation actually selected', function () {
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);

        Storyfeed::activity()
            ->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => $name]))
            ->for($project)
            ->publish();
    }

    expect(fn () => HeadlineCoverage::assertCoversGroups())
        ->toThrow(AssertionFailedError::class, 'actors.upload (no group headline)');

    Storyfeed::aggregateGrammar(['actors.upload' => ':actors uploaded :count files to :target']);

    HeadlineCoverage::assertCoversGroups();
});

it('fails aggregate coverage when nothing is grouped on an aggregate axis', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    // Passing over an empty set would prove nothing.
    expect(fn () => HeadlineCoverage::assertCoversGroups())
        ->toThrow(AssertionFailedError::class, 'proves nothing');
});

it('asserts a declared aggregate matrix proactively', function () {
    Storyfeed::aggregateGrammar(['actors.upload' => ':actors uploaded :count files']);

    // assertCoversGroups() only sees combinations the data produced;
    // the matrix form asserts what COULD occur.
    expect(fn () => HeadlineCoverage::assertCoversAggregateMatrix(['actors', 'targets'], ['upload', 'comment']))
        ->toThrow(AssertionFailedError::class, 'targets.upload (no group headline)');

    Storyfeed::aggregateGrammar([
        'actors.comment' => ':actors commented on :target',
        'targets.*' => ':actor acted on :count things',
    ]);

    HeadlineCoverage::assertCoversAggregateMatrix(['actors', 'targets'], ['upload', 'comment']);
});

it('keeps GrammarCoverage and its aggregate method names as deprecated aliases', function () {
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);

        Storyfeed::activity()
            ->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => $name]))
            ->for($project)
            ->publish();
    }

    expect(fn () => GrammarCoverage::assertCoversAggregates())
        ->toThrow(AssertionFailedError::class, 'group headline coverage is incomplete');
    expect(fn () => GrammarCoverage::assertCoversPossibleAggregates())
        ->toThrow(AssertionFailedError::class, 'group headline coverage is incomplete');

    Storyfeed::aggregateGrammar([
        'actors.upload' => ':actors uploaded :count files to :target',
        'targets.upload' => ':actor uploaded files to :targets',
        'object.upload' => ':actor uploaded :object :count times',
        'repeat.upload' => ':actor uploaded :count files',
    ]);

    GrammarCoverage::assertCoversAggregates();
    GrammarCoverage::assertCoversPossibleAggregates();
    GrammarCoverage::assertCoversGroups();
    expect(new GrammarCoverage)->toBeInstanceOf(HeadlineCoverage::class);
});
