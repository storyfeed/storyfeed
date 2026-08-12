<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('reports missing grammar and icons for emitted verbs', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $this->artisan('storyfeed:doctor')
        ->expectsOutputToContain('No grammar entry resolves for `delivery.confirm`')
        ->expectsOutputToContain('No icon resolves for `delivery.confirm`')
        ->assertSuccessful();
});

it('reports healthy when coverage is complete', function () {
    Storyfeed::grammar(['*.*' => ':actor acted'])
        ->icons(['*.*' => 'bi-lightning'])
        ->verbs(['confirm' => 'Update']);

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $this->artisan('storyfeed:doctor')
        ->expectsOutputToContain('Storyfeed looks healthy')
        ->assertSuccessful();
});

it('reports the snapshot backlog', function () {
    Activity::query()->create([
        'verb' => 'confirm',
        'object_type' => 'delivery',
        'object_id' => 999,
        'published_at' => now(),
    ]);

    Storyfeed::grammar(['*.*' => ':actor acted'])->icons(['*.*' => 'bi-lightning']);

    $this->artisan('storyfeed:doctor')
        ->expectsOutputToContain('uncached entities')
        ->assertSuccessful();
});

it('reports missing aggregate grammar for axes actually in use', function () {
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);

        Storyfeed::activity()
            ->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => $name]))
            ->for($project)
            ->publish();
    }

    $this->artisan('storyfeed:doctor')
        ->expectsOutputToContain('No aggregate grammar resolves for `actors.upload`')
        ->assertSuccessful();

    Storyfeed::aggregateGrammar(['actors.upload' => ':actors uploaded :count files to :target']);

    $this->artisan('storyfeed:doctor')
        ->doesntExpectOutputToContain('No aggregate grammar resolves for `actors.upload`')
        ->assertSuccessful();
});

it('warns about grouping hashes at the column length limit', function () {
    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    Grouping::query()
        ->where('activity_id', $activity->id)
        ->where('bucket', 'repeat')
        ->update(['hash' => str_repeat('x', 255)]);

    $this->artisan('storyfeed:doctor')
        ->expectsOutputToContain('255-character column limit')
        ->assertSuccessful();
});

it('warns when an aggregate template references a token its axis does not pin', function () {
    // The screenshot bug: ":object" on the repeat axis rendered "made 5
    // revisions to Aut Beatae.docx" over five different documents.
    Storyfeed::aggregateGrammar([
        'repeat.revise' => ':actor made :count revisions to :object',
        'object.revise' => ':actor made :count revisions to :object',
        '*.upload' => ':actors uploaded :count files',
    ]);

    $this->artisan('storyfeed:doctor')
        ->expectsOutputToContain('Aggregate template `repeat.revise` references `:object`')
        ->doesntExpectOutputToContain('Aggregate template `object.revise`')
        ->doesntExpectOutputToContain('Aggregate template `*.upload`')
        ->assertSuccessful();
});
