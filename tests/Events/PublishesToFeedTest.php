<?php

use Illuminate\Support\Facades\Event;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\Contracts\PublishesToFeed;
use Storyfeed\Exceptions\UnknownStory;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\PendingStory;
use Storyfeed\Story;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Events\DeliveryConfirmed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveryWasConfirmed;

/*
 * Events driving the feed, through ONE interface-registered listener.
 */

beforeEach(function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);
});

it('publishes when an implementing event is dispatched', function () {
    // PINS FRAMEWORK BEHAVIOUR: Illuminate\Events\Dispatcher::getListeners()
    // calls addInterfaceListeners(), which walks class_implements(). The whole
    // wiring rests on that, so a Laravel change must fail OUR ci rather than a
    // consumer's feed.
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $customer = Customer::create(['name' => 'Acme Co.']);

    DeliveryConfirmed::dispatch($delivery, $user, $customer);

    $items = Storyfeed::feed()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['verb'])->toBe('confirm')
        ->and($items[0]['actor']['label'])->toBe('Sally')
        ->and($items[0]['target']['label'])->toBe('Acme Co.')
        // The Story authored the headline; the event only supplied the roles.
        ->and($items[0]['headline_template'])->toBe(':actor confirmed :object for :target');
});

it('costs nothing for events that do not implement the contract', function () {
    // The reason this is an interface listener and not a wildcard: a wildcard
    // would be invoked for every event the app dispatches, including every
    // Eloquent lifecycle event.
    Event::dispatch(new class {});

    expect(Storyfeed::feed()->get()->toArray()['items'])->toBeEmpty();
});

it('treats a null return as a deliberate skip', function () {
    $event = new class implements PublishesToFeed
    {
        public function toFeedStory(): ?PendingStory
        {
            return null; // nothing happened worth telling
        }
    };

    Event::dispatch($event);

    expect(Storyfeed::feed()->get()->toArray()['items'])->toBeEmpty();
});

it('supports declaring the activity inline, with no Story class', function () {
    Storyfeed::grammar(['delivery.archive' => ':actor archived :object']);

    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    $event = new class($delivery) implements PublishesToFeed
    {
        public function __construct(public Delivery $delivery) {}

        public function toFeedStory(): ?PendingStory
        {
            return PendingStory::inline('archive')->object($this->delivery);
        }
    };

    Event::dispatch($event);

    expect(Storyfeed::feed()->get()->toArray()['items'][0]['verb'])->toBe('archive');
});

it('accepts a verb enum inline', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object']);

    expect(PendingStory::inline(ActivityVerb::Confirm)->activity->verb)->toBe('confirm');
});

it('refuses to publish an unregistered Story rather than a verbless row', function () {
    $unregistered = new class extends Story
    {
        public string|array|null $objectType = 'delivery';

        public string|FeedVerb|BackedEnum|null $verb = 'ghost';

        public function headline(): string
        {
            return ':actor did something';
        }
    };

    try {
        PendingStory::of($unregistered);
        $this->fail('Expected an UnknownStory.');
    } catch (UnknownStory $e) {
        expect($e->getMessage())
            ->toContain('is not registered')
            ->toContain('Storyfeed::stories([')
            // Says what would otherwise happen.
            ->toContain('nobody authored a headline for');
    }
});

it('refuses a non-Story class, pointing at inline() instead', function () {
    expect(fn () => PendingStory::of(Delivery::class))
        ->toThrow(UnknownStory::class, 'PendingStory::inline($verb)');
});

it('keeps the whole builder surface, with no parallel API to drift', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    // Inherited from PendingActivity — actor/for/data/when/publishedAt all
    // present without a single forwarder written here.
    $activity = PendingStory::of(DeliveryWasConfirmed::class)
        ->object($delivery)
        ->actor($user)
        ->data(['note' => 'ok'])
        ->when(true, fn ($story) => $story->publishedAt(now()->subHour()))
        ->publish();

    expect($activity->data)->toBe(['note' => 'ok'])
        ->and($activity->published_at->isBefore(now()->subMinutes(30)))->toBeTrue();
});

it('does nothing when Event::fake() is active — the one silent failure', function () {
    // Documented rather than worked around: EventFake short-circuits the real
    // dispatcher, so the interface listener never runs and event-driven
    // publishing goes quiet. Event::fake([OtherEvent::class]) is the fix.
    Event::fake();

    DeliveryConfirmed::dispatch(
        Delivery::create(['tracking_number' => 'TN-1']),
        User::create(['name' => 'Sally', 'email' => 'sally@example.com']),
    );

    Event::assertDispatched(DeliveryConfirmed::class);

    expect(Storyfeed::feed()->get()->toArray()['items'])->toBeEmpty();
});
