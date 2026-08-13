<?php

use Storyfeed\Contracts\DiagnosticCheck;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\StoryfeedManager;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The structured half of doctor: findings as data, so an application can
 * render feed health itself instead of shelling out to Artisan and scraping
 * the CLI output (which is what the first consumer had to do).
 */

function confirmOne(): void
{
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();
}

it('returns findings as data with stable codes', function () {
    confirmOne();

    $report = Storyfeed::doctor();

    expect($report->isHealthy())->toBeFalse()
        ->and($report->has('grammar.missing'))->toBeTrue()
        ->and($report->has('grammar.icon_missing'))->toBeTrue()
        ->and($report->withCode('grammar.missing')->first()->subject)
        ->toBe(['type' => 'delivery', 'verb' => 'confirm']);
});

it('counts only warnings and errors, never info', function () {
    Storyfeed::grammar(['*.confirm' => ':actor confirmed'])->icons(['*.confirm' => 'bi-check']);

    confirmOne();

    // Scoped to the checks this test reasons about: `surface` legitimately
    // warns here, because the workbench declares Feedable models that publish
    // nothing, and that is its job.
    $report = Storyfeed::doctor(['grammar', 'verbs']);

    // `confirm` has no AS2 mapping here, which is an Info note — reportage,
    // not a finding. A tool that counts notes as problems is one people stop
    // reading, which is the same failure as one that skips a category.
    expect($report->all()->contains(fn (Finding $f) => $f->severity === Severity::Info))->toBeTrue()
        ->and($report->isHealthy())->toBeTrue()
        ->and($report->count())->toBe(0);
});

it('derives stub tokens from the axis recipe, never from guesswork', function () {
    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))
            ->publish();
    }

    $fix = Storyfeed::doctor(['aggregates'])
        ->fixes()
        ->first(fn ($fix) => str_starts_with($fix->key, 'repeat.'));

    expect($fix)->not->toBeNull()
        ->and($fix->registry)->toBe('aggregateGrammar')
        // The repeat axis pins actor and target; it does NOT pin :object,
        // and a snippet offering :object is the documented lie class.
        ->and($fix->tokens)->toContain(':actor')
        ->and($fix->tokens)->not->toContain(':object')
        ->and($fix->snippet())->toContain("'repeat.upload' =>");
});

it('prints stubs as bare code, with nothing to strip before pasting', function () {
    confirmOne();

    $this->artisan('storyfeed:doctor --stubs')
        ->expectsOutputToContain('Storyfeed::grammar([')
        ->doesntExpectOutputToContain('finding(s)')
        ->doesntExpectOutputToContain('healthy')
        ->assertSuccessful();
});

it('emits a machine-readable report', function () {
    confirmOne();

    $this->artisan('storyfeed:doctor --json')->assertSuccessful();

    $report = Storyfeed::doctor()->toArray();

    expect(json_decode((string) json_encode($report), true))
        ->toHaveKeys(['healthy', 'count', 'severity', 'findings'])
        ->and($report['findings'][0])->toHaveKeys(['code', 'severity', 'message', 'subject', 'fix']);
});

it('limits the run to named checks', function () {
    confirmOne();

    expect(Storyfeed::doctor(['grammar'])->all()->pluck('code')->unique()->all())
        ->each->toStartWith('grammar.');
});

it('lists every check name, including app-registered ones', function () {
    Storyfeed::checks([new class implements DiagnosticCheck
    {
        public function name(): string
        {
            return 'custom';
        }

        public function run(StoryfeedManager $storyfeed): iterable
        {
            return [];
        }
    }]);

    $this->artisan('storyfeed:doctor --list')
        ->expectsOutputToContain('grammar')
        ->expectsOutputToContain('custom')
        ->assertSuccessful();
});

it('exits zero by default and non-zero only when asked', function () {
    confirmOne();

    // Doctor has always been safe to run anywhere; schedulers depend on that.
    $this->artisan('storyfeed:doctor')->assertSuccessful();
    $this->artisan('storyfeed:doctor --fail-on=warning')->assertFailed();

    // Warnings present but no errors, so an error floor still passes.
    $this->artisan('storyfeed:doctor --fail-on=error')->assertSuccessful();
});

it('rejects a nonsense --fail-on rather than ignoring it', function () {
    $this->artisan('storyfeed:doctor --fail-on=info')
        ->expectsOutputToContain('expects `warning` or `error`')
        ->assertExitCode(2);
});

it('reports a throwing check as a finding instead of dying with it', function () {
    // A check once queried the very column whose absence it existed to
    // report, so a real drift crashed the diagnosis rather than naming it.
    // A diagnostic that dies on the condition it diagnoses is worse than none.
    Storyfeed::checks([new class implements DiagnosticCheck
    {
        public function name(): string
        {
            return 'exploding';
        }

        public function run(StoryfeedManager $storyfeed): iterable
        {
            throw new RuntimeException('boom');
            yield;
        }
    }]);

    confirmOne();

    $report = Storyfeed::doctor();

    expect($report->has('doctor.check_failed'))->toBeTrue()
        ->and($report->withCode('doctor.check_failed')->first()->message)->toContain('boom')
        // The point: the other checks still ran.
        ->and($report->has('grammar.missing'))->toBeTrue();
});

it('warns when the feed has stopped being written to', function () {
    config()->set('storyfeed.doctor.stale_after', 30);
    Storyfeed::grammar(['*.*' => ':actor acted'])->icons(['*.*' => 'bi-dot']);

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))
        ->publishedAt(now()->subDays(45))
        ->publish();

    expect(Storyfeed::doctor(['freshness'])->has('freshness.stale'))->toBeTrue();

    config()->set('storyfeed.doctor.stale_after', null);

    expect(Storyfeed::doctor(['freshness'])->all())->toBeEmpty();
});

it('renders verb drift from the same check doctor uses', function () {
    Storyfeed::grammar(['*.*' => ':actor acted'])->icons(['*.*' => 'bi-dot']);

    Storyfeed::activity()->verb('confrim')->publish();

    // One implementation, two views — two implementations of one question is
    // how the two commands came to disagree once before.
    expect(Storyfeed::doctor(['verbs'])->has('verbs.undeclared'))->toBeTrue();

    $this->artisan('storyfeed:verbs --used')
        ->expectsOutputToContain('Recorded but never declared')
        ->expectsOutputToContain('confrim')
        ->assertSuccessful();
});
