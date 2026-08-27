<?php

use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The half of AggregateCoverage that is about the READER, not the database.
 *
 * The check has been technically correct and practically misleading twice —
 * silent on an empty database, then asking a `->live()` dashboard for five
 * `object.*` templates that its mode could never render. These tests are what
 * stop a third: they pin the two sentences apart, and they pin the honest
 * degradation, which is the part that fails quietly if it ever regresses.
 */

/**
 * Two uploads of the SAME object by the SAME actor — an `object` cluster
 * (key `aa:aid:v:oa!:oid!:d`, min 2 members), which outranks the `repeat`
 * fallback in curation priority. The consumer's shape exactly.
 */
function objectCluster(string $verb = 'upload'): void
{
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $file = Delivery::create(['tracking_number' => 'TN-1']);

    foreach (range(1, 2) as $ignored) {
        Storyfeed::activity()->actor($user)->verb($verb, $file)->publish();
    }
}

it('reports every pair and says reachability is unknown when no feeds are registered', function () {
    objectCluster();

    $report = Storyfeed::doctor(['aggregates']);

    // The absence of a registry is an absence of INFORMATION. It must never
    // downgrade a warning, and it must never read as an all-clear.
    expect($report->has('aggregates.missing'))->toBeTrue()
        ->and($report->has('aggregates.latent'))->toBeFalse()
        ->and($report->has('aggregates.reachability_unknown'))->toBeTrue()
        ->and($report->withCode('aggregates.reachability_unknown')->first()->message)
        ->toContain('read-mode reachability is unknown')
        ->and($report->withCode('aggregates.missing')->first()->severity)
        ->toBe(Severity::Warning);
});

it('calls an object-axis pair latent when every registered feed reads live', function () {
    Storyfeed::feeds(['dashboard' => fn (FeedBuilder $feed) => $feed->live()]);

    objectCluster();

    $report = Storyfeed::doctor(['aggregates']);
    $latent = $report->withCode('aggregates.latent')->first();

    // `live()` selects bucket = 'repeat' plus authored composites and never
    // consults the winner column — so no object.* template could ever fire.
    expect($latent)->not->toBeNull()
        ->and($latent->subject['axis'])->toBe('object')
        ->and($latent->subject['modes'])->toBe('live')
        ->and($latent->severity)->toBe(Severity::Info)
        // Still reported, and still not a gap to go fix: a stub here is six
        // registrations that cannot render.
        ->and($latent->fix)->toBeNull()
        ->and($report->fixes()->contains(fn ($fix) => str_starts_with($fix->key, 'object.')))->toBeFalse()
        ->and($report->all()->contains(
            fn ($f) => $f->code === 'aggregates.missing' && $f->subject['axis'] === 'object'
        ))->toBeFalse();
});

it('still warns, with a stub, for a pair a registered feed can actually read', function () {
    Storyfeed::feeds(['newsroom' => fn (FeedBuilder $feed) => $feed->summary()]);

    objectCluster();

    $report = Storyfeed::doctor(['aggregates']);
    $missing = $report->withCode('aggregates.missing')->first();

    expect($report->has('aggregates.latent'))->toBeFalse()
        ->and($missing->subject['axis'])->toBe('object')
        ->and($missing->subject['read_by'])->toBe('newsroom')
        ->and($missing->fix)->not->toBeNull()
        ->and($report->has('aggregates.reachability_unknown'))->toBeFalse();
});

it('does not call a pair latent on the strength of a verb a live feed does read', function () {
    // `repeat` IS live-readable, so this is the control for the test above:
    // the mode filter must narrow by axis, not blanket-excuse a live app.
    Storyfeed::feeds(['dashboard' => fn (FeedBuilder $feed) => $feed->live()]);

    $user = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))
            ->publish();
    }

    $report = Storyfeed::doctor(['aggregates']);

    expect($report->all()->contains(
        fn ($f) => $f->code === 'aggregates.missing' && $f->subject['axis'] === 'repeat'
    ))->toBeTrue();
});

it('never claims unreadable when a feed would not inspect', function () {
    Storyfeed::feeds([
        'dashboard' => fn (FeedBuilder $feed) => $feed->live(),
        'broken' => fn (FeedBuilder $feed) => throw new RuntimeException('boom'),
    ]);

    objectCluster();

    $report = Storyfeed::doctor(['aggregates']);

    // One feed whose mode is unreadable makes "nothing reads this" unsayable
    // for the whole run — the object pair reverts to a plain warning.
    expect($report->has('aggregates.latent'))->toBeFalse()
        ->and($report->has('aggregates.missing'))->toBeTrue()
        ->and($report->withCode('aggregates.reachability_unknown')->first()->message)
        ->toContain('RuntimeException');
});

it('holds a feed to the verbs it declared, not just its mode', function () {
    // summary() reads every axis, but this feed cannot show `upload` at all,
    // so it is no evidence that anything renders the pair.
    Storyfeed::feeds(['orders' => fn (FeedBuilder $feed) => $feed->summary()->only(['order.*'])]);

    objectCluster();

    expect(Storyfeed::doctor(['aggregates'])->has('aggregates.latent'))->toBeTrue();
});
