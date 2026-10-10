<?php

use Composer\InstalledVersions;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Testing\HeadlineCoverage;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * #106: two apps had doctor findings in production and nobody acted on them.
 * Nothing in a failing report said how to gate on it in CI, which assertion
 * would have kept it out, or which install produced it.
 */

it('names its own flags after the findings, on a healthy run too', function () {
    $this->artisan('storyfeed:doctor', ['--only' => ['media']])
        ->expectsOutputToContain('Storyfeed looks healthy.')
        ->expectsOutputToContain('--fail-on=error   exit non-zero when an error is present (`warning` gates on warnings too)')
        ->expectsOutputToContain('--only=<check>    report one check at a time; --list names them')
        ->expectsOutputToContain('--json            the same report, machine-readable')
        ->assertSuccessful();
});

it('names the assertion that guards a coverage finding, and only where one does', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $file = Delivery::create(['tracking_number' => 'TN-1']);

    foreach (range(1, 2) as $ignored) {
        Storyfeed::activity()->actor($sally)->verb('upload', $file)->publish();
    }
    Storyfeed::activity()->verb('confirm', $file)->publish();

    $report = Storyfeed::doctor(['grammar', 'aggregates', 'actorless']);
    $headline = $report->withCode('grammar.missing')->first();
    $group = $report->withCode('aggregates.missing')->first();
    $actorless = $report->withCode('actorless.missing')->first();

    expect($headline->fix->guard)->toBe(HeadlineCoverage::class.'::assertCoversRecorded()')
        ->and($headline->message)->toEndWith('Guard it in a test with `HeadlineCoverage::assertCoversRecorded()`.')
        ->and($report->withCode('grammar.icon_missing')->first()->fix->guard)->toBe(HeadlineCoverage::class.'::assertCoversRecorded()')
        ->and($group->fix->guard)->toBe(HeadlineCoverage::class.'::assertCoversGroups()')
        ->and($group->message)->toEndWith('Guard it in a test with `HeadlineCoverage::assertCoversGroups()`.')
        // No assertion covers actorless templates: no guard, not a wrong one.
        ->and($actorless->fix->guard)->toBeNull()
        ->and($actorless->message)->not->toContain('Guard it')
        ->and($headline->toArray()['fix']['guard'])->toBe(HeadlineCoverage::class.'::assertCoversRecorded()');
});

it('stamps the report with every installed storyfeed package', function () {
    $data = InstalledVersions::getAllRawData()[0];
    $root = $data['root']['name'];

    InstalledVersions::reload([...$data, 'versions' => [...$data['versions'], 'storyfeed/ui' => [
        'pretty_version' => 'dev-main', 'version' => 'dev-main', 'reference' => 'abcdef1234567890abcdef1234567890abcdef12',
        'type' => 'library', 'install_path' => __DIR__, 'aliases' => [], 'dev_requirement' => false,
    ]]]);

    try {
        $rootVersion = InstalledVersions::getPrettyVersion($root);

        $this->artisan('storyfeed:doctor', ['--only' => ['media']])
            ->expectsOutputToContain('Installed: storyfeed/ui dev-main@abcdef1')
            // The root package's reference is not HEAD: no commit, not a wrong one.
            ->expectsOutput("Installed: {$root} {$rootVersion}")
            ->assertSuccessful();

        Artisan::call('storyfeed:doctor', ['--only' => ['media'], '--json' => true]);
        $json = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        expect($json['installed'])->toBe([
            $root => ['version' => $rootVersion, 'reference' => null],
            'storyfeed/ui' => ['version' => 'dev-main', 'reference' => 'abcdef1234567890abcdef1234567890abcdef12'],
        ]);
    } finally {
        InstalledVersions::reload($data);
    }
});
