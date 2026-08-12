<?php

use PHPUnit\Framework\AssertionFailedError;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Axis;
use Storyfeed\StoryfeedManager;
use Storyfeed\Testing\GrammarCoverage;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Axis applicability, derived from the compiled recipe instead of reasoned
 * about. The package has always owned this answer as bitmasks; it just never
 * exposed it, so consumers re-derived it by hand in their own test suites —
 * and got it wrong (a written analysis said `join` could not group on the
 * object axis; one run of real traffic disagreed).
 */

it('derives required roles from the recipe', function () {
    // actors: 'v:ta!:tid:d' — target required, actor free to vary.
    expect(Storyfeed::axis('actors')->requiredRoles())->toBe(['target'])
        // targets: 'aa!:aid:v:d' — actor required.
        ->and(Storyfeed::axis('targets')->requiredRoles())->toBe(['actor'])
        // object: 'aa:aid:v:oa!:oid!:d' — object required.
        ->and(Storyfeed::axis('object')->requiredRoles())->toBe(['object'])
        // repeat pins everything but requires nothing.
        ->and(Storyfeed::axis('repeat')->requiredRoles())->toBe([]);
});

it('reports undecidable rather than universal for row-backed axes', function () {
    // The dangerous default would be "applies to everything": that would let a
    // coverage tool confidently deny a gap it cannot actually see.
    expect(Storyfeed::axis('composite')->requiredRoles())->toBeNull()
        ->and(Storyfeed::axis('composite')->appliesToRoles(['actor', 'object']))->toBeFalse();
});

it('reports undecidable for closure recipes too', function () {
    Storyfeed::axes([
        Axis::make('bespoke')->key(fn ($activity) => 'k')->pins(':actor'),
    ]);

    expect(Storyfeed::axis('bespoke')->requiredRoles())->toBeNull();
});

it('answers which axes apply to a set of filled roles', function () {
    // An actor-only activity cannot group on `actors` (needs a target) or
    // `object` (needs an object), but `targets` and `repeat` both apply.
    expect(Storyfeed::axesApplicableTo(['actor']))
        ->toContain('targets')
        ->toContain('repeat')
        ->not->toContain('actors')
        ->not->toContain('object');
});

it('derives the (axis, verb) matrix from observed role-fill', function () {
    $pairs = Storyfeed::possibleAggregatePairs([
        'upload' => ['actor', 'object', 'target'],
        'sync' => [],
    ]);

    expect($pairs)->toContain(['actors', 'upload'])
        ->toContain(['targets', 'upload'])
        ->toContain(['object', 'upload'])
        // A role-less verb reaches only the axis that requires nothing.
        ->toContain(['repeat', 'sync'])
        ->not->toContain(['object', 'sync'])
        ->not->toContain(['actors', 'sync']);
});

it('replaces a hand-partitioned matrix with one derived assertion', function () {
    Storyfeed::fake();

    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $customer = Customer::create(['name' => 'Acme Co.']);

    Storyfeed::activity()->actor($user)
        ->verb('upload', Delivery::create(['tracking_number' => 'TN-1']))
        ->for($customer)
        ->publish();

    // Authored for every axis the recipe says `upload` can reach — and
    // NOTHING was hand-listed to get here.
    Storyfeed::aggregateGrammar([
        'actors.upload' => ':actors uploaded :count files to :target',
        'targets.upload' => ':actor uploaded files to :targets',
        'object.upload' => ':actor uploaded :object :count times',
        'repeat.upload' => ':actor uploaded :count files',
    ]);

    GrammarCoverage::assertCoversPossibleAggregates();
});

it('fails naming the missing cells, and names what it could not check', function () {
    Storyfeed::fake();

    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    Storyfeed::activity()->actor($user)
        ->verb('upload', Delivery::create(['tracking_number' => 'TN-1']))
        ->publish();

    try {
        GrammarCoverage::assertCoversPossibleAggregates();
        $this->fail('Expected the coverage assertion to fail.');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())
            ->toContain('targets.upload (no aggregate headline)')
            ->toContain('object.upload (no aggregate headline)')
            // Points at the command that prints the fix.
            ->toContain('storyfeed:doctor --stubs')
            // States its own limit rather than implying completeness.
            ->toContain('not every shape the verb can take')
            // A skipped category must never read as a clean one.
            ->toContain('NOT checked here')
            ->toContain('composite');
    }
});

it('refuses to pass vacuously when nothing was published', function () {
    Storyfeed::fake();

    try {
        GrammarCoverage::assertCoversPossibleAggregates();
        $this->fail('Expected the coverage assertion to fail.');
    } catch (AssertionFailedError $e) {
        expect($e->getMessage())->toContain('proves nothing');
    }
});

it('unions roles across recordings of the same verb', function () {
    Storyfeed::fake();

    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $customer = Customer::create(['name' => 'Acme Co.']);

    // Once without a target, once with. The verb can clearly take one, and a
    // per-row view would conclude otherwise for the first recording.
    Storyfeed::activity()->actor($user)->verb('upload', Delivery::create(['tracking_number' => 'TN-1']))->publish();
    Storyfeed::activity()->actor($user)->verb('upload', Delivery::create(['tracking_number' => 'TN-2']))->for($customer)->publish();

    expect(app(StoryfeedManager::class)->recordedRoles()['upload'])
        ->toContain('actor')->toContain('object')->toContain('target');
});
