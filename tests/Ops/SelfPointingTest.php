<?php

use Illuminate\Support\Facades\Artisan;
use Storyfeed\Diagnostics\Finding;
use Storyfeed\Diagnostics\Fix;
use Storyfeed\Diagnostics\Installed;
use Storyfeed\Diagnostics\Report;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * Doctor pointing at its own capabilities — todo 937, and the SHA stamp from 954.
 *
 * The premise of all three: documentation was NOT the lever. A good page
 * existed, an attentive consumer working on exactly this problem never reached
 * it, and a broken sentence shipped. So the assertions here are about what the
 * tool says at the moment someone has the problem.
 */
function w122Cluster(): void
{
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);

        Storyfeed::activity()
            ->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => $name]))
            ->for($project)
            ->publish();
    }
}

it('names its own flags, and says what each one does', function () {
    Storyfeed::grammar(['*.*' => ':actor acted'])->icons(['*.*' => 'bi-lightning']);

    Artisan::call('storyfeed:doctor');

    // Each flag says what it DOES. A bare list of names is what --help already
    // is, and --help is the thing two consumers never opened.
    expect(Artisan::output())
        ->toContain('--fail-on=error')
        ->toContain('exit non-zero when an error is present')
        ->toContain('--only=<check>')
        ->toContain('report one check at a time')
        ->toContain('--json')
        ->toContain('machine-readable');
});

it('names its flags on a healthy run too — the CI gate is worth adopting before there is a problem', function () {
    Storyfeed::grammar(['*.*' => ':actor acted'])
        ->icons(['*.*' => 'bi-lightning'])
        ->verbs(['confirm' => 'Update']);

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    Artisan::call('storyfeed:doctor');

    expect(Artisan::output())
        ->toContain('Storyfeed looks healthy')
        ->toContain('--fail-on=error');
});

it('points a missing aggregate template at the assertion that guards it, in the finding itself', function () {
    w122Cluster();

    Artisan::call('storyfeed:doctor', ['--only' => ['aggregates']]);

    // Twice: in the finding's own prose, and once in the footer as the guard
    // to add. The distance to close is finding -> assertion, not finding ->
    // docs site -> assertion.
    expect(Artisan::output())
        ->toContain('GrammarCoverage::assertCoversAggregates()')
        ->toContain('Keep this from coming back');
});

it('carries the guard on the Fix, so --json and a consumer UI can reach it too', function () {
    w122Cluster();

    $report = Storyfeed::doctor(['aggregates']);
    $missing = $report->withCode('aggregates.missing');

    expect($missing)->not->toBeEmpty();

    // In the prose a consumer pastes into a bug report, as well as on the Fix.
    expect($missing->first()->message)
        ->toContain('GrammarCoverage::assertCoversAggregates()');

    expect($missing->first()->fix?->guard)
        ->toBe('Storyfeed\Testing\GrammarCoverage::assertCoversAggregates()');

    $guards = array_column(array_column($report->toArray()['findings'], 'fix'), 'guard');

    expect($guards)->toContain('Storyfeed\Testing\GrammarCoverage::assertCoversAggregates()');
});

it('says the guard once, however many pairs are missing', function () {
    $report = new Report([
        Finding::warning('aggregates.missing', 'a', [], Fix::make('aggregateGrammar', 'targets.upload', guard: 'X::y()')),
        Finding::warning('aggregates.missing', 'b', [], Fix::make('aggregateGrammar', 'object.upload', guard: 'X::y()')),
    ]);

    expect($report->guards()->all())->toBe(['X::y()']);
});

it('offers no guard where no shipped assertion actually catches the finding', function () {
    Storyfeed::grammar(['*.*' => ':actor acted'])->icons(['*.*' => 'bi-lightning']);

    Storyfeed::activity()->anonymously()->verb('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $report = Storyfeed::doctor(['actorless']);

    expect($report->withCode('actorless.missing'))->not->toBeEmpty();
    expect($report->guards()->all())->toBe([]);
});

it('stamps human output with the package the run came from', function () {
    Storyfeed::grammar(['*.*' => ':actor acted'])->icons(['*.*' => 'bi-lightning']);

    Artisan::call('storyfeed:doctor');

    expect(Artisan::output())
        ->toContain('Installed:')
        ->toContain('storyfeed/storyfeed');
});

it('adds the install to toArray without reshaping a key a consumer may already parse', function () {
    $report = new Report([], new Installed([
        'storyfeed/storyfeed' => ['version' => 'dev-main', 'reference' => 'abc123def4567890', 'root' => false],
    ]));

    expect(array_keys($report->toArray()))
        ->toBe(['healthy', 'count', 'severity', 'findings', 'installed']);

    expect($report->toArray()['installed']['storyfeed/storyfeed']['reference'])->toBe('abc123def4567890');
});

it('says an unresolvable commit is unresolved rather than guessing one', function () {
    expect((new Installed)->line())
        ->toContain('no `storyfeed/*` package could be resolved')
        ->toContain('cite yours by hand');

    expect((new Installed)->toArray())->toBe([]);
});

it('refuses to cite the root package commit, which is stamped at install time and does not track HEAD', function () {
    $installed = new Installed([
        'storyfeed/storyfeed' => ['version' => 'dev-main', 'reference' => null, 'root' => true],
    ]);

    expect($installed->line())
        ->toContain('storyfeed/storyfeed dev-main')
        ->toContain('root package — no installed commit to cite');
});

it('stamps the plugin alongside core when both are installed', function () {
    $installed = new Installed([
        'storyfeed/filament' => ['version' => '1.2.0', 'reference' => 'ffffffffffff0000', 'root' => false],
        'storyfeed/storyfeed' => ['version' => 'dev-main', 'reference' => 'abc123def4567890', 'root' => false],
    ]);

    expect($installed->line())
        ->toContain('storyfeed/filament 1.2.0@ffffffffffff')
        ->toContain('storyfeed/storyfeed dev-main@abc123def456');
});

it('reports a version it cannot read as unknown, not as absent', function () {
    $installed = new Installed([
        'storyfeed/ui' => ['version' => null, 'reference' => null, 'root' => false],
    ]);

    expect($installed->line())->toContain('storyfeed/ui version unknown (commit unknown)');
});
