<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Axis;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The point of the Axis formalization: a NEW axis needs zero package
 * edits. One registration drives the strategy, curation, the payload,
 * aggregate grammar, and doctor's token validation end to end.
 */

it('drives a custom axis through the whole pipeline from one registration', function () {
    // "Activity in one context/project" — collapse when 2+ distinct actors
    // act in the same context on the same day, whatever they did.
    Storyfeed::axes([
        Axis::make('scene')
            ->key('v:ca!:cid!:d')
            ->eligibleWhenDistinct('actor', min: 2),
    ], merge: false);

    Storyfeed::axes([
        Axis::make('repeat')->key('aa:aid:v:oa:ta:tid:d')->fallback(),
    ]);

    Storyfeed::aggregateGrammar(['scene.comment' => ':actors commented in :context']);

    $project = Customer::create(['name' => 'Brand Refresh']);

    foreach (['Bob', 'Sally'] as $name) {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);

        Storyfeed::activity()
            ->actor($user)
            ->verb('comment', Delivery::create(['tracking_number' => "N-{$name}"]))
            ->context($project)
            ->publish();
    }

    // Strategy wrote the custom bucket; curation stamped it.
    expect(Grouping::query()->where('bucket', 'scene')->count())->toBe(2)
        ->and(Grouping::query()->where('bucket', 'scene')->where('winner', true)->count())->toBe(2);

    $items = Storyfeed::feed()->get()->toArray()['items'];

    // The payload carries the custom axis (renderers must treat unknown
    // axes as generic groups — contract), with the aggregate headline and
    // the derived :context pin honoured.
    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('scene')
        ->and($items[0]['count'])->toBe(2)
        ->and($items[0]['headline_template'])->toBe(':actors commented in :context')
        ->and($items[0]['exemplars']['object'])->toBeNull();
});

it('treats registration order as curation priority', function () {
    // Reverse the built-in priority: object above actors.
    $defaults = Storyfeed::registeredAxes();

    Storyfeed::axes([
        $defaults['object'], $defaults['actors'], $defaults['targets'], $defaults['repeat'],
    ], merge: false);

    $project = Customer::create(['name' => 'Concur']);
    $doc = Delivery::create(['tracking_number' => 'Aut Beatae.docx']);

    // Bob revises the doc twice; Sally and Ann once each — actors AND
    // object are both eligible for Bob's rows. With object registered
    // first, Bob's pair now wins object (the inverse of CurationTest's
    // 'actors beat object' expectation under default order).
    $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    Storyfeed::activity()->actor($bob)->verb('revise', $doc)->for($project)->publish();
    Storyfeed::activity()->actor($bob)->verb('revise', $doc)->for($project)->publish();

    foreach (['Sally', 'Ann'] as $name) {
        $user = User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
        Storyfeed::activity()->actor($user)->verb('revise', $doc)->for($project)->publish();
    }

    $axes = collect(Storyfeed::feed()->limit(10)->get()->toArray()['items'])->pluck('axis');

    expect($axes)->toContain('object');
});
