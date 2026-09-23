<?php

use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Group;
use Storyfeed\Grouping\GroupBuilder;
use Storyfeed\Stories\Story as StoryClass;
use Storyfeed\Stories\Verb;
use Storyfeed\Tests\Fixtures\Stories\DeliveryWasSigned;
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

/*
 * A resource Story class is about its type as plainly as Story::for() is:
 * `DeliveryStory::place()` and `CustomerStory::place()` must not file their
 * group headlines in the same place, or one type's rows read as the other's.
 */

it('files a resource class headline under its type, so two classes sharing a verb keep their own', function () {
    $deliveries = new class
    {
        public function place(Verb $verb): Verb
        {
            return $verb->headline(':actor placed :object')
                ->grouped(Group::repeat()->headline(':actor placed :count deliveries'));
        }
    };

    $customers = new class
    {
        public function place(Verb $verb): Verb
        {
            return $verb->headline(':actor placed :object')
                ->grouped(Group::repeat()->headline(':actor placed :count customers'));
        }
    };

    Story::resource(Delivery::class, $deliveries::class)->only('place');
    Story::resource(Customer::class, $customers::class)->only('place');

    expect(Storyfeed::registeredAggregateGrammar())->not->toHaveKey('repeat.place')
        ->and(Storyfeed::aggregateTemplate('repeat', 'place', 'delivery'))->toBe(':actor placed :count deliveries')
        ->and(Storyfeed::aggregateTemplate('repeat', 'place', 'customer'))->toBe(':actor placed :count customers');
});

it('never lends one type\'s headline to another type with the same verb', function () {
    $deliveries = new class
    {
        public function place(Verb $verb): Verb
        {
            return $verb->headline(':actor placed :object')
                ->grouped(Group::repeat()->headline(':actor placed :count deliveries'));
        }
    };

    Story::resource(Delivery::class, $deliveries::class)->only('place');
    Story::for(Customer::class)->verb('place')->headline(':actor placed :object');

    // Three customers placed must not read "placed 3 deliveries".
    expect(Storyfeed::aggregateTemplate('repeat', 'place', 'customer'))->toBeNull();
});

it('refuses a resource class headline on a grouping that can hold several types, naming the method', function () {
    $deliveries = new class
    {
        public function place(Verb $verb): Verb
        {
            return $verb->grouped(Group::byActors()->headline(':actors placed :count deliveries'));
        }
    };

    Story::resource(Delivery::class, $deliveries::class)->only('place');

    expect(fn () => Storyfeed::compiledStories())->toThrow(function (StoryMisconfigured $e) {
        expect($e->getMessage())
            ->toContain('::place() gives its Group::byActors() grouping a headline')
            ->toContain('other kinds of thing in the same row')
            ->toContain('things that are not all [delivery]')
            ->toContain("routes/feed.php instead, worded so it names no type: Story::verb('place')->grouped(Group::byActors()->headline('…'))")
            ->not->toContain('actors.place');
    });
});

/*
 * A one-verb Story class is about its type too: `DeliveryWasPlaced` and
 * `CustomerWasPlaced` must not file their group headlines in the same place.
 */

it('files a one-verb class headline under its type, so two classes sharing a verb keep their own', function () {
    $deliveries = new class extends StoryClass
    {
        public string|array|null $objectType = Delivery::class;

        public string|FeedVerb|BackedEnum|null $verb = 'place';

        public function headline(): string
        {
            return ':actor placed :object';
        }

        public function groups(): array
        {
            return [Group::repeat()->headline(':actor placed :count deliveries')];
        }
    };

    $customers = new class extends StoryClass
    {
        public string|array|null $objectType = Customer::class;

        public string|FeedVerb|BackedEnum|null $verb = 'place';

        public function headline(): string
        {
            return ':actor placed :object';
        }

        public function groups(): array
        {
            return [Group::repeat()->headline(':actor placed :count customers')];
        }
    };

    Storyfeed::stories([$deliveries::class, $customers::class]);

    expect(Storyfeed::registeredAggregateGrammar())->not->toHaveKey('repeat.place')
        ->and(Storyfeed::aggregateTemplate('repeat', 'place', 'delivery'))->toBe(':actor placed :count deliveries')
        ->and(Storyfeed::aggregateTemplate('repeat', 'place', 'customer'))->toBe(':actor placed :count customers');
});

it('refuses a one-verb class headline on a grouping that can hold several types, naming the class', function () {
    Storyfeed::stories([DeliveryWasSigned::class]);

    expect(fn () => Storyfeed::compiledStories())->toThrow(function (StoryMisconfigured $e) {
        expect($e->getMessage())
            ->toStartWith(DeliveryWasSigned::class.'::groups() gives its Group::byTargets() grouping a headline')
            ->toContain('things that are not all [delivery]')
            ->toContain("routes/feed.php instead, worded so it names no type: Story::verb('sign')->grouped(Group::byTargets()->headline('…'))");
    });
});

it('keeps an object-less one-verb class on axis.verb', function () {
    $story = new class extends StoryClass
    {
        public string|array|null $objectType = '*';

        public string|FeedVerb|BackedEnum|null $verb = 'sweep';

        public function headline(): string
        {
            return ':actor swept';
        }

        public function groups(): array
        {
            return [Group::byActors()->headline(':actors swept :count times')];
        }
    };

    Storyfeed::stories([$story::class]);

    expect(Storyfeed::registeredAggregateGrammar())->toHaveKey('actors.sweep');
});
