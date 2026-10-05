<?php

use Storyfeed\Diagnostics\Reachability;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\Models\Grouping;
use Storyfeed\StoryfeedManager;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The half of AggregateCoverage that is about the READER, not the database.
 *
 * The check has been technically correct and practically misleading twice —
 * silent on an empty database, then asking a repeats-only dashboard for five
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
    // downgrade an error, and it must never read as an all-clear.
    expect($report->has('aggregates.missing'))->toBeTrue()
        ->and($report->has('aggregates.latent'))->toBeFalse()
        ->and($report->has('aggregates.reachability_unknown'))->toBeTrue()
        ->and($report->withCode('aggregates.reachability_unknown')->first()->message)
        ->toContain('read-mode reachability is unknown')
        ->and($report->withCode('aggregates.missing')->first()->severity)
        ->toBe(Severity::Error);
});

it('calls an object-axis pair latent when every registered feed reads the digest', function () {
    Storyfeed::feeds(['dashboard' => fn (FeedBuilder $feed) => $feed->summary()]);

    objectCluster();

    $report = Storyfeed::doctor(['aggregates']);
    $latent = $report->withCode('aggregates.latent')->first();

    // `summary()` selects the period's partition bucket and never consults
    // the winner column — so no object.* template could ever fire.
    expect($latent)->not->toBeNull()
        ->and($latent->subject['axis'])->toBe('object')
        ->and($latent->subject['modes'])->toBe('summary')
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
    Storyfeed::feeds(['newsroom' => fn (FeedBuilder $feed) => $feed->live()]);

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
    // `repeat` IS live-readable, so this is the control: the mode filter must
    // narrow by axis, not blanket-excuse a live app.
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
    // live() reads every axis, but this feed cannot show `upload` at all,
    // so it is no evidence that anything renders the pair.
    Storyfeed::feeds(['orders' => fn (FeedBuilder $feed) => $feed->live()->only(['order.*'])]);

    objectCluster();

    expect(Storyfeed::doctor(['aggregates'])->has('aggregates.latent'))->toBeTrue();
});

it('detects unstamped repeat gaps with curation off in the actual live payload', function () {
    config()->set('storyfeed.grouping.curate', false);
    Storyfeed::feeds(['newsroom' => fn (FeedBuilder $feed) => $feed->live()]);
    objectCluster();

    $items = Storyfeed::feed()->live()->get()->toArray()['items'];
    $report = Storyfeed::doctor(['aggregates']);
    expect($report->withCode('aggregates.missing'))->toHaveCount(1);
    $missing = $report->withCode('aggregates.missing')->sole();

    expect(Grouping::query()->whereNotNull('winner')->count())->toBe(0)
        ->and($items)->toHaveCount(1)
        ->and($items[0]['kind'])->toBe('group')
        ->and($items[0]['axis'])->toBe('repeat')
        ->and($items[0]['count'])->toBe(2)
        ->and($missing->subject)->toBe([
            'axis' => 'repeat', 'verb' => 'upload', 'key' => 'repeat.delivery.upload', 'read_by' => 'newsroom',
        ])
        ->and($missing->severity)->toBe(Severity::Error)
        ->and($missing->fix->key)->toBe('repeat.delivery.upload')
        ->and($report->has('aggregates.latent'))->toBeFalse()
        ->and($report->has('aggregates.reachability_unknown'))->toBeFalse();

    Storyfeed::aggregateGrammar(['repeat.delivery.upload' => ':actor uploaded :count deliveries']);

    expect(Storyfeed::doctor(['aggregates'])->all())->toBeEmpty();
});

it('ignores historical non-repeat winners with curation off while auditing live repeats', function () {
    Storyfeed::feeds(['newsroom' => fn (FeedBuilder $feed) => $feed->live()]);
    objectCluster();

    $stamps = Grouping::query()->where('winner', true)->pluck('id')->all();
    expect(Grouping::query()->where('winner', true)->pluck('bucket')->unique()->all())->toBe(['object'])
        ->and(Storyfeed::feed()->live()->get()->toArray()['items'][0]['axis'])->toBe('object');

    config()->set('storyfeed.grouping.curate', false);

    $items = Storyfeed::feed()->live()->get()->toArray()['items'];
    $report = Storyfeed::doctor(['aggregates']);
    expect(Grouping::query()->where('winner', true)->pluck('id')->all())->toBe($stamps)
        ->and($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('repeat')
        ->and($items[0]['count'])->toBe(2)
        ->and($report->has('aggregates.latent'))->toBeFalse();

    expect($report->withCode('aggregates.missing')->sole()->subject['key'])->toBe('repeat.delivery.upload');

    // Resolving repeat must clear the report, even though the old object
    // winners remain stored with no object grammar registered.
    Storyfeed::aggregateGrammar(['repeat.delivery.upload' => ':actor uploaded :count deliveries']);

    expect(Storyfeed::doctor(['aggregates'])->all())->toBeEmpty();
});

it('restricts live reachability to repeat with curation off and preserves mode and verb filters', function () {
    config()->set('storyfeed.grouping.curate', false);
    Storyfeed::feeds([
        'newsroom' => fn (FeedBuilder $feed) => $feed->live(),
        'orders' => fn (FeedBuilder $feed) => $feed->live()->only(['order.*']),
        'digest' => fn (FeedBuilder $feed) => $feed->summary(),
        'audit' => fn (FeedBuilder $feed) => $feed->log(),
    ]);

    $reach = Reachability::of(app(StoryfeedManager::class));
    expect($reach->readers('repeat', 'upload'))->toBe(['newsroom'])
        ->and($reach->readers('repeat', 'order.place'))->toBe(['newsroom', 'orders'])
        ->and($reach->readers('object', 'upload'))->toBe([])
        ->and($reach->readers('actors', 'upload'))->toBe([])
        ->and($reach->readers('summary', 'upload'))->toBe(['digest'])
        ->and($reach->isConclusive())->toBeTrue();
});

it('keeps curation-off repeat gaps latent when declared feeds cannot read them', function (string $surface) {
    config()->set('storyfeed.grouping.curate', false);
    Storyfeed::feeds(['dashboard' => fn (FeedBuilder $feed) => match ($surface) {
        'summary' => $feed->summary(),
        'log' => $feed->log(),
        'filtered live' => $feed->live()->only(['order.*']),
    }]);
    objectCluster();

    $report = Storyfeed::doctor(['aggregates']);
    $latent = $report->withCode('aggregates.latent')->sole();
    expect($latent->subject['key'])->toBe('repeat.delivery.upload')
        ->and($latent->severity)->toBe(Severity::Info)
        ->and($latent->fix)->toBeNull()
        ->and($report->has('aggregates.missing'))->toBeFalse()
        ->and($report->has('aggregates.reachability_unknown'))->toBeFalse()
        ->and($report->fixes())->toBeEmpty();
})->with(['summary', 'log', 'filtered live']);

it('keeps unknown reachability at error severity for curation-off repeats', function (bool $opaque) {
    config()->set('storyfeed.grouping.curate', false);
    if ($opaque) {
        Storyfeed::feeds([
            'digest' => fn (FeedBuilder $feed) => $feed->summary(),
            'broken' => fn (FeedBuilder $feed) => throw new RuntimeException('boom'),
        ]);
    }
    objectCluster();

    $report = Storyfeed::doctor(['aggregates']);
    $missing = $report->withCode('aggregates.missing')->sole();
    expect($missing->subject['key'])->toBe('repeat.delivery.upload')
        ->and($missing->subject['read_by'])->toBeNull()
        ->and($missing->severity)->toBe(Severity::Error)
        ->and($missing->fix)->not->toBeNull()
        ->and($report->has('aggregates.latent'))->toBeFalse()
        ->and($report->has('aggregates.reachability_unknown'))->toBeTrue()
        ->and($report->all()->first()->code)->toBe('aggregates.reachability_unknown');
})->with(['no feeds' => false, 'opaque feed' => true]);

it('does not audit singleton repeats with curation off', function () {
    config()->set('storyfeed.grouping.curate', false);
    Storyfeed::feeds(['newsroom' => fn (FeedBuilder $feed) => $feed->live()]);
    Storyfeed::activity('upload', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $items = Storyfeed::feed()->live()->get()->toArray()['items'];
    expect($items)->toHaveCount(1)
        ->and($items[0]['kind'])->toBe('activity')
        ->and(Storyfeed::doctor(['aggregates'])->all())->toBeEmpty();
});
