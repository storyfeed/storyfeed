<?php

use PHPUnit\Framework\AssertionFailedError;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\Testing\GrammarCoverage;
use Storyfeed\Testing\HeadlineCoverage;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('passes when every pair has a headline and an icon', function () {
    Story::for('delivery')->verb('confirm')->headline(':actor confirmed :object')->icon('bi-truck');

    HeadlineCoverage::assertCovers([['delivery', 'confirm']]);
});

it('fails and names what is missing', function () {
    Story::for('delivery')->verb('confirm')->headline(':actor confirmed :object');

    expect(fn () => HeadlineCoverage::assertCovers([['delivery', 'confirm']]))
        ->toThrow(AssertionFailedError::class, 'delivery.confirm (no icon)');
});

it('does not accept a wildcard catch-all as coverage', function () {
    Story::fallback()->headline(':actor acted')->icon('bi-lightning');

    // A *.* entry resolves for everything, which would make coverage vacuous.
    expect(fn () => HeadlineCoverage::assertCovers([['delivery', 'confirm']]))
        ->toThrow(AssertionFailedError::class);

    HeadlineCoverage::assertCovers([['delivery', 'confirm']], allowWildcard: true);
});

it('accepts a partial wildcard as deliberate authoring', function () {
    Story::for('delivery')->fallback()->headline(':actor did something to :object');
    Story::verb('confirm')->icon('bi-check');

    HeadlineCoverage::assertCovers([['delivery', 'confirm']]);
});

it('asserts coverage for everything the fake recorded', function () {
    Story::for('delivery')->verb('confirm')->headline(':actor confirmed :object');
    Story::for('delivery')->verb('upload')->headline(':actor uploaded :object');
    Story::for('delivery')->verb('confirm')->icon('bi-truck');
    Story::for('delivery')->verb('upload')->icon('bi-upload');

    Storyfeed::fake();

    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity('confirm', $delivery)->publish();
    Storyfeed::activity('upload', $delivery)->publish();

    HeadlineCoverage::assertCoversRecorded();
});

it('catches an activity type nobody authored', function () {
    Story::for('delivery')->verb('confirm')->headline(':actor confirmed :object')->icon('bi-truck');

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
    Story::for('delivery')->verb('confirm')->headline(':actor confirmed :object')->icon('bi-truck');

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    HeadlineCoverage::assertCoversPublished();
});

it('asserts aggregate grammar for the axes curation actually selected', function () {
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);

        Storyfeed::activity()
            ->actor($user)
            ->verb('upload', Delivery::firstOrCreate(['tracking_number' => 'Shared']))
            ->for($project)
            ->publish();
    }

    expect(fn () => HeadlineCoverage::assertCoversGroups())
        ->toThrow(AssertionFailedError::class, 'actors.delivery.upload (no group headline)');

    Story::verb('upload')->grouped(Group::on('actors')->headline(':actors uploaded :count files to :target'));
    Story::verb('upload')->grouped(Group::byActorsOnTarget()->headline(':actors uploaded :objects to :target'));

    HeadlineCoverage::assertCoversGroups();
});

it('fails aggregate coverage when nothing is grouped on an aggregate axis', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    // Passing over an empty set would prove nothing.
    expect(fn () => HeadlineCoverage::assertCoversGroups())
        ->toThrow(AssertionFailedError::class, 'proves nothing');
});

it('asserts a declared aggregate matrix proactively', function () {
    Story::verb('upload')->grouped(Group::on('actors')->headline(':actors uploaded :count files'));

    // assertCoversGroups() only sees combinations the data produced;
    // the matrix form asserts what COULD occur.
    expect(fn () => HeadlineCoverage::assertCoversAggregateMatrix(['actors', 'targets'], ['upload', 'comment']))
        ->toThrow(AssertionFailedError::class, 'targets.upload (no group headline)');

    Story::verb('comment')->grouped(Group::on('actors')->headline(':actors commented on :target'));
    Story::fallback()->grouped(Group::on('targets')->headline(':actor acted on :count things'));

    HeadlineCoverage::assertCoversAggregateMatrix(['actors', 'targets'], ['upload', 'comment']);
});

it('keeps GrammarCoverage and its aggregate method names as deprecated aliases', function () {
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);

        Storyfeed::activity()
            ->actor($user)
            ->verb('upload', Delivery::firstOrCreate(['tracking_number' => 'Shared']))
            ->for($project)
            ->publish();
    }

    expect(fn () => GrammarCoverage::assertCoversAggregates())
        ->toThrow(AssertionFailedError::class, 'group headline coverage is incomplete');
    expect(fn () => GrammarCoverage::assertCoversPossibleAggregates())
        ->toThrow(AssertionFailedError::class, 'group headline coverage is incomplete');

    Story::verb('upload')->grouped(Group::on('actors')->headline(':actors uploaded :count files to :target'));
    Story::verb('upload')->grouped(Group::byActorsOnTarget()->headline(':actors uploaded :objects to :target'));
    Story::verb('upload')->grouped(Group::on('targets')->headline(':actor uploaded files to :targets'));
    Story::verb('upload')->grouped(Group::on('object')->headline(':actor uploaded :object :count times'));
    Story::verb('upload')->grouped(Group::on('repeat')->headline(':actor uploaded :count files'));

    GrammarCoverage::assertCoversAggregates();
    GrammarCoverage::assertCoversPossibleAggregates();
    GrammarCoverage::assertCoversGroups();
    expect(new GrammarCoverage)->toBeInstanceOf(HeadlineCoverage::class);
});
