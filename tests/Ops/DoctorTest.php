<?php

use Storyfeed\Diagnostics\Severity;
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

it('warns on :context outside a context-pinning axis — stricter than the old hand map', function () {
    // The hand-maintained token map allowed :context on repeat/actors by
    // accident; no built-in recipe includes the context pair, so it was
    // never homogeneous. Derivation from the recipe fixed the leniency.
    Storyfeed::aggregateGrammar(['actors.upload' => ':actors uploaded :count files in :context']);

    $this->artisan('storyfeed:doctor')
        ->expectsOutputToContain('Aggregate template `actors.upload` references `:context`')
        ->assertSuccessful();
});

it('notes aggregate grammar keys that reference unregistered axes', function () {
    Storyfeed::aggregateGrammar(['actrs.upload' => ':actors uploaded :count files']);

    $this->artisan('storyfeed:doctor')
        ->expectsOutputToContain('references axis `actrs`, which is not registered')
        ->assertSuccessful();
});

it('audits the fallback axis for aggregate coverage — a missing repeat.* key is visible', function () {
    // How `archive` slipped four rounds: repeat groups render aggregate
    // headlines like any other axis, but the coverage audit used to filter
    // on aggregateAxes(), which excludes the fallback by definition.
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($sally)->verb('archive', Delivery::create(['tracking_number' => "TN-{$i}"]))->publish();
    }

    $this->artisan('storyfeed:doctor')
        ->expectsOutputToContain('No aggregate grammar resolves for `repeat.archive`')
        ->assertSuccessful();

    Storyfeed::aggregateGrammar(['repeat.archive' => ':actor archived :count deliveries']);

    $this->artisan('storyfeed:doctor')
        ->doesntExpectOutputToContain('No aggregate grammar resolves for `repeat.archive`')
        ->assertSuccessful();
});

it('does not flag fallback verbs that only ever appear as singletons', function () {
    // A repeat winner in a cluster of ONE renders as a plain activity node —
    // no aggregate headline needed, no warning earned. Healthy must mean
    // healthy.
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $this->artisan('storyfeed:doctor')
        ->doesntExpectOutputToContain('No aggregate grammar resolves for `repeat.confirm`')
        ->assertSuccessful();
});

it('can produce a coverage finding for EVERY registered axis', function () {
    // A coverage tool silently skipping a category is indistinguishable
    // from a healthy system (the fallback axis was invisible for four
    // versions). So: prove each axis is AUDITABLE — a deliberately
    // ungrammared cluster on every registered axis must yield a finding.
    $project = Customer::create(['name' => 'Concur']);

    // actors: three actors, one verb+target.
    foreach (['A1', 'A2', 'A3'] as $name) {
        $u = User::create(['name' => $name, 'email' => "{$name}@example.com"]);
        Storyfeed::activity()->actor($u)->verb('alpha', Delivery::create(['tracking_number' => "al-{$name}"]))->for($project)->publish();
    }

    // targets: one actor, one verb, three distinct targets.
    $t = User::create(['name' => 'T', 'email' => 't@example.com']);
    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($t)->verb('beta', Delivery::create(['tracking_number' => "be-{$i}"]))
            ->for(Customer::create(['name' => "Target-{$i}"]))->publish();
    }

    // object: one actor, one object, twice.
    $o = User::create(['name' => 'O', 'email' => 'o@example.com']);
    $doc = Delivery::create(['tracking_number' => 'ga-1']);
    Storyfeed::activity()->actor($o)->verb('gamma', $doc)->publish();
    Storyfeed::activity()->actor($o)->verb('gamma', $doc)->publish();

    // repeat (the fallback): one actor, distinct objects, no target.
    $r = User::create(['name' => 'R', 'email' => 'r@example.com']);
    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($r)->verb('delta', Delivery::create(['tracking_number' => "de-{$i}"]))->publish();
    }

    // composite (row-backed): an explicit authored story.
    $c = User::create(['name' => 'C', 'email' => 'c@example.com']);
    Storyfeed::activity('epsilon')->actor($c)->objects([
        Delivery::create(['tracking_number' => 'ep-1']),
        Delivery::create(['tracking_number' => 'ep-2']),
    ])->publish();

    $this->artisan('storyfeed:doctor')
        ->expectsOutputToContain('No aggregate grammar resolves for `actors.alpha`')
        ->expectsOutputToContain('No aggregate grammar resolves for `targets.beta`')
        ->expectsOutputToContain('No aggregate grammar resolves for `object.gamma`')
        ->expectsOutputToContain('No aggregate grammar resolves for `repeat.delta`')
        ->expectsOutputToContain('No aggregate grammar resolves for `composite.epsilon`')
        ->assertSuccessful();
});

it('accepts plural tokens on every axis, singular still pinned-only', function () {
    Storyfeed::aggregateGrammar([
        'targets.add' => ':actor added :count items in :targets',   // plural: fine
        'repeat.complete' => ':actor completed :objects',            // plural: fine
        '*.archive' => ':actors archived :objects in :contexts',     // wildcard + plurals: fine
        'repeat.revise' => ':actor revised :object',                 // singular unpinned: still a lie
    ]);

    $this->artisan('storyfeed:doctor')
        ->doesntExpectOutputToContain('`targets.add`')
        ->doesntExpectOutputToContain('`repeat.complete`')
        ->doesntExpectOutputToContain('`*.archive`')
        ->expectsOutputToContain('Aggregate template `repeat.revise` references `:object`')
        ->assertSuccessful();
});

it('reports a check name that matches nothing, rather than running nothing quietly', function () {
    $findings = collect(Storyfeed::doctor(['grammer'])->all());

    // The motivating case: an empty report because nothing ran looks exactly
    // like a clean one, and a CI gate on --only= goes green on a check that
    // never executed.
    expect($findings->pluck('code')->all())->toBe(['doctor.unknown_check'])
        ->and($findings->first()->severity)->toBe(Severity::Warning)
        ->and($findings->first()->message)->toContain('grammar')  // lists the valid names
        ->and($findings->first()->message)->toContain('participants');
});

it('still runs the names that DID match alongside the unknown one', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $codes = collect(Storyfeed::doctor(['grammer', 'grammar'])->all())->pluck('code');

    expect($codes)->toContain('doctor.unknown_check')
        ->and($codes->filter(fn (string $code) => str_starts_with($code, 'grammar.')))->not->toBeEmpty();
});

it('fails the build under --fail-on=warning, which is the point of the finding', function () {
    // Info severity would leave the build green and the vacuous pass alive,
    // with a line in the report that merely LOOKS like the system noticed.
    $this->artisan('storyfeed:doctor --only=grammer --fail-on=warning')
        ->assertFailed();

    $this->artisan('storyfeed:doctor --only=grammar --fail-on=warning')
        ->assertSuccessful();
});

it('warns when recording is switched off outside testing', function () {
    config()->set('storyfeed.recording.enabled', false);

    app()->detectEnvironment(fn () => 'production');

    $report = Storyfeed::doctor(['recording']);

    expect($report->has('recording.disabled'))->toBeTrue()
        ->and($report->withCode('recording.disabled')->first()->severity)->toBe(Severity::Warning)
        ->and($report->withCode('recording.disabled')->first()->subject)
        ->toBe(['environment' => 'production', 'config' => false]);
});

it('notes rather than warns when recording is switched off under testing', function () {
    Storyfeed::stopRecording();

    $report = Storyfeed::doctor(['recording']);

    expect($report->has('recording.disabled'))->toBeTrue()
        ->and($report->withCode('recording.disabled')->first()->severity)->toBe(Severity::Info)
        ->and($report->isHealthy())->toBeTrue();
});

it('says nothing about recording when the feed is being written', function () {
    expect(Storyfeed::doctor(['recording'])->all())->toBeEmpty();
});
