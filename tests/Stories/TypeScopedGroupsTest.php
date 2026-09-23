<?php

use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\Grouping\GroupBuilder;
use Storyfeed\Stories\Verb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

/*
 * Group headlines inside Story::for(): keyed per type (`repeat.delivery.ship`)
 * where the axis pins the object type, and an error where it doesn't, since
 * the group could then hold several types.
 */

it('keys a group headline per type inside a type scope', function () {
    Story::for(Delivery::class)->group(function () {
        Story::verb('ship')->headline(':actor shipped :object')
            ->grouped(fn (GroupBuilder $group) => $group->repeat(':actor shipped :count deliveries'));
    });

    Story::for(Customer::class)->verb('ship')->headline(':actor shipped to :object')
        ->grouped(Group::byObject()->headline(':actors shipped to :object'));

    expect(Storyfeed::registeredAggregateGrammar())
        ->toHaveKey('repeat.delivery.ship')
        ->toHaveKey('object.customer.ship')
        ->not->toHaveKey('repeat.ship')
        // The read path tries the per-type key first.
        ->and(Storyfeed::aggregateTemplate('repeat', 'ship', 'delivery'))->toBe(':actor shipped :count deliveries')
        ->and(Storyfeed::aggregateTemplate('repeat', 'ship', 'customer'))->toBeNull();
});

it('keys one per type for a list of types', function () {
    Story::for([Delivery::class, Customer::class])->verb('ship')
        ->grouped(Group::repeat()->headline(':actor shipped :count times'));

    expect(Storyfeed::registeredAggregateGrammar())->toHaveKeys(['repeat.delivery.ship', 'repeat.customer.ship']);
});

it('refuses an axis that does not pin the object type, pointing at the verb', function () {
    Story::for(Delivery::class)->verb('ship')
        ->grouped(Group::byActors()->headline(':actors shipped :count deliveries'));

    Storyfeed::compiledStories();
})->throws(StoryMisconfigured::class, "Story::verb('ship')->grouped(…)");

it('refuses a group headline on a type fallback', function () {
    Story::for(Delivery::class)->fallback()
        ->grouped(Group::repeat()->headline(':actor did :count things'));

    Storyfeed::compiledStories();
})->throws(StoryMisconfigured::class, 'fallback() a group headline');

it('keeps axis.verb for an unscoped verb and for the other authoring forms', function () {
    Story::verb('ship')->grouped(Group::byActors()->headline(':actors shipped :count things'));

    Storyfeed::stories([
        Verb::for(Delivery::class, 'hold')
            ->grouped(Group::byActors()->headline(':actors held :count things')),
    ]);

    expect(Storyfeed::registeredAggregateGrammar())->toHaveKeys(['actors.ship', 'actors.hold']);
});
