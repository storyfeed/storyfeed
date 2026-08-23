<?php

use Storyfeed\Demo\Cast;
use Storyfeed\Demo\DemoSeeder;
use Storyfeed\Demo\Screenplay;
use Storyfeed\Demo\Vocabulary;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Party;
use Workbench\App\Models\Delivery;

function seed_demo(int $days = 3, int $seed = 1): int
{
    $cast = Cast::studio();

    return (new DemoSeeder($cast, new Screenplay($cast, days: $days, seed: $seed)))->seed();
}

it('seeds a feed that has something to show', function () {
    $published = seed_demo();

    expect($published)->toBeGreaterThan(0)
        ->and(Activity::count())->toBe($published);
});

it('is deterministic: the same seed reproduces the same feed', function () {
    $cast = Cast::studio();

    $first = (new Screenplay($cast, days: 5, seed: 7))->beats();
    $second = (new Screenplay($cast, days: 5, seed: 7))->beats();
    $different = (new Screenplay($cast, days: 5, seed: 8))->beats();

    $shape = fn ($beats) => array_map(
        fn ($b) => [$b->verb, $b->actor, $b->object, $b->target, $b->context, $b->publishedAt->toIso8601String()],
        $beats,
    );

    // A rehearsed demo must be the demo that appears on stage.
    expect($shape($first))->toBe($shape($second))
        ->and($shape($different))->not->toBe($shape($first));
});

it('publishes every entity as a typed party rather than a bare service node', function () {
    seed_demo(days: 5);

    $parties = Party::where('key', 'like', 'demo-%')->get();

    expect($parties)->not->toBeEmpty();

    // Typing matters because it is what the AS2.0 surface serializes; a cast that
    // resolved through the manager's default would be a page of `Service` nodes.
    $types = $parties->pluck('type')->unique()->values()->all();

    expect($types)->each->toBeIn(['Person', 'Organization', 'Object', 'Document', 'Note'])
        ->and($types)->toContain('Person')
        ->and($types)->toContain('Document');

    // The key prefix is what makes a demo row identifiable at a glance.
    expect($parties->every(fn ($p) => str_starts_with($p->key, 'demo-')))->toBeTrue();
});

it('produces group nodes, not a wall of solo rows', function () {
    seed_demo(days: 3);

    Vocabulary::register();

    $items = Storyfeed::feed()->limit(50)->summary()->get()->toArray()['items'];

    $groups = array_filter($items, fn ($i) => $i['kind'] === 'group');
    $solos = array_filter($items, fn ($i) => $i['kind'] !== 'group');

    // The mix is the point: all-groups reads as unconvincingly as all-rows.
    expect($groups)->not->toBeEmpty()
        ->and($solos)->not->toBeEmpty();
});

it('gives its group nodes headlines, which is what makes the demo look alive', function () {
    seed_demo(days: 3);

    Vocabulary::register();

    $items = Storyfeed::feed()->limit(50)->summary()->get()->toArray()['items'];
    $groups = array_values(array_filter($items, fn ($i) => $i['kind'] === 'group'));

    expect($groups)->not->toBeEmpty();

    // A group node with a null headline is the visible failure of an unregistered
    // aggregate grammar — the exact thing that would be discovered on stage.
    foreach ($groups as $group) {
        expect($group['headline_template'] ?? $group['headline'])->not->toBeNull();
    }
});

it('fills a world feed, a context feed and an actor feed', function () {
    seed_demo(days: 3);

    $project = Party::find(Cast::keyFor('Brand Refresh'));
    $person = Party::find(Cast::keyFor('Priya Raman'));

    expect(Storyfeed::feed()->limit(10)->get()->toArray()['items'])->not->toBeEmpty()
        ->and(Storyfeed::feed()->context($project)->limit(10)->get()->toArray()['items'])->not->toBeEmpty()
        ->and(Storyfeed::feed()->actor($person)->log()->limit(10)->get()->toArray()['items'])->not->toBeEmpty();
});

it('marks every seeded activity as demo data in its payload', function () {
    seed_demo(days: 1);

    expect(Activity::pluck('data')->every(fn ($data) => ($data['demo'] ?? false) === true))->toBeTrue();
});

it('prefixes every seeded verb, which is what makes teardown safe', function () {
    seed_demo(days: 2);

    expect(Activity::pluck('verb')->every(fn ($v) => str_starts_with($v, Vocabulary::PREFIX)))->toBeTrue();
});

it('removes what it seeded, including grouping rows', function () {
    seed_demo(days: 2);

    expect(Activity::count())->toBeGreaterThan(0)
        ->and(Grouping::count())->toBeGreaterThan(0);

    $removed = DemoSeeder::fresh();

    expect($removed['activities'])->toBeGreaterThan(0)
        ->and($removed['parties'])->toBeGreaterThan(0)
        ->and(Activity::withTrashed()->count())->toBe(0)
        ->and(Grouping::count())->toBe(0)
        ->and(Party::where('key', 'like', 'demo-%')->count())->toBe(0);
});

it('cannot delete an activity the application published', function () {
    // The failure this guards against is the worst one the kit could have: a demo
    // command that reaches real rows.
    $delivery = Delivery::create(['tracking_number' => 'TN-REAL']);
    Storyfeed::activity('confirm', $delivery)->publish();

    $party = Party::make('Real Integration');

    seed_demo(days: 1);

    DemoSeeder::fresh();

    expect(Activity::count())->toBe(1)
        ->and(Activity::first()->verb)->toBe('confirm')
        ->and(Party::find($party->key))->not->toBeNull();
});
