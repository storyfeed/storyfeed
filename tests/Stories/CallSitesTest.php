<?php

use Illuminate\Support\Facades\Artisan;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Exceptions\UnknownStory;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\PendingActivity;
use Storyfeed\Tests\Fixtures\Stories\DeliveryStory;
use Storyfeed\Tests\Fixtures\Stories\DeliveryWasDispatched;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveryWasConfirmed;

/*
 * Call sites, read the way Laravel reads routes: the verb is the public
 * handle, as a route's name is, and `story('ship', $order)` is the feed's
 * `route('orders.ship', $order)`. A resource Story class is a declaration
 * call sites never touch. A one-verb Story class is the invokable
 * controller: bound to its verb in routes/feed.php, and — being Job-shaped —
 * still publishable at the call site with `OrderWasShipped::of($order)`.
 */

// ── story() ─────────────────────────────────────────────────────────────

it('publishes a verb by name, as route() names a route', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    $activity = story('ship', $delivery)->by($user)->publish();

    expect($activity->verb)->toBe('ship')
        ->and($activity->object_id)->toEqual($delivery->id)
        ->and($activity->actor_id)->toEqual($user->id);
});

it('takes no object, or an enum case', function () {
    $pending = story(ActivityVerb::Confirm);

    expect($pending)->toBeInstanceOf(PendingActivity::class)
        ->and(story('ping')->publish()->verb)->toBe('ping')
        ->and($pending->object(Delivery::create(['tracking_number' => 'TN-1']))->publish()->verb)->toBe('confirm');
});

it('leaves an app that already defines story() alone', function () {
    $helper = new ReflectionFunction('story');

    expect(realpath((string) $helper->getFileName()))->toBe(realpath(__DIR__.'/../../src/helpers.php'))
        ->and(file_get_contents((string) $helper->getFileName()))->toContain("if (! function_exists('story')) {");
});

// ── of() ────────────────────────────────────────────────────────────────

it('begins a one-verb class and a verb enum with the same word', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    $viaClass = DeliveryWasConfirmed::of(Delivery::create(['tracking_number' => 'A']))->publish();
    $viaEnum = ActivityVerb::Confirm->of(Delivery::create(['tracking_number' => 'B']))->publish();

    expect($viaClass->verb)->toBe('confirm')
        ->and($viaEnum->verb)->toBe('confirm')
        ->and($viaClass->object_type)->toBe($viaEnum->object_type);
});

it('drops the old words: Story::activity() and the enum\'s action()', function () {
    expect(method_exists(DeliveryWasConfirmed::class, 'activity'))->toBeFalse()
        ->and(method_exists(ActivityVerb::Confirm, 'action'))->toBeFalse();
});

it('says which of() takes the object when given a Story class', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);

    // A string object is a party's name: without the guard, this would
    // publish an activity about a party called "Workbench\App\Stories\…".
    expect(fn () => DeliveryWasConfirmed::of(DeliveryWasConfirmed::class))
        ->toThrow(UnknownStory::class, "DeliveryWasConfirmed::of() takes the activity's object")
        ->and(fn () => DeliveryWasConfirmed::of(DeliveryWasConfirmed::class))
        ->toThrow(UnknownStory::class, 'PendingActivity::of() is the one that takes a Story class');
});

it('says which of() takes the class when given a model', function () {
    expect(fn () => PendingActivity::of(Delivery::class))
        ->toThrow(UnknownStory::class, 'PendingActivity::of() takes a Story class, and the object comes after');
});

// ── Story::for(…)->verb('x', OneVerbClass::class) ───────────────────────

it('binds a one-verb class to its verb, and the class learns it', function () {
    Story::for(Delivery::class)->verb('dispatch', DeliveryWasDispatched::class);

    $activity = DeliveryWasDispatched::of(Delivery::create(['tracking_number' => 'TN-1']))->publish();

    expect($activity->verb)->toBe('dispatch')
        ->and(DeliveryWasDispatched::verb())->toBe('dispatch')
        ->and(Storyfeed::template('delivery', 'dispatch'))->toBe(':actor dispatched :object')
        ->and(Storyfeed::icon('delivery', 'dispatch'))->toBe('truck')
        ->and(Storyfeed::hasStory(DeliveryWasDispatched::class))->toBeTrue()
        ->and(PendingActivity::of(DeliveryWasDispatched::class)->publish()->verb)->toBe('dispatch');
});

it('binds inside a group with Story::verb(), and chains on the scope', function () {
    Story::for(Delivery::class)->group(function () {
        Story::verb('dispatch', DeliveryWasDispatched::class);
    });

    Story::for('customer')
        ->verb('dispatch', DeliveryWasDispatched::class)
        ->verb('offboard', fn ($verb) => $verb->headline(':actor offboarded :object'));

    expect(Storyfeed::template('delivery', 'dispatch'))->toBe(':actor dispatched :object')
        ->and(Storyfeed::template('customer', 'dispatch'))->toBe(':actor dispatched :object')
        ->and(Storyfeed::template('customer', 'offboard'))->toBe(':actor offboarded :object');
});

it('gives one class one verb', function () {
    Story::for(Delivery::class)->verb('dispatch', DeliveryWasDispatched::class);
    Story::for(Customer::class)->verb('onboard', DeliveryWasDispatched::class);

    Storyfeed::compiledStories();
})->throws(StoryMisconfigured::class, 'is bound to the verbs [dispatch] and [onboard]');

it('binds one class to its verb on several types', function () {
    Story::for([Delivery::class, Customer::class])->verb('dispatch', DeliveryWasDispatched::class);

    expect(Storyfeed::template('delivery', 'dispatch'))->toBe(':actor dispatched :object')
        ->and(Storyfeed::template('customer', 'dispatch'))->toBe(':actor dispatched :object');
});

it('uses the class\'s own type outside a group', function () {
    Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);

    expect(Storyfeed::template('delivery', 'confirm'))->toBe(':actor confirmed :object for :target');
});

it('needs a type from somewhere outside a group', function () {
    Story::verb('dispatch', DeliveryWasDispatched::class);

    Storyfeed::compiledStories();
})->throws(StoryMisconfigured::class, 'must declare $objectType');

it('keeps a class\'s enum case, and with it the AS2.0 type', function () {
    Story::for(Delivery::class)->verb('confirm', DeliveryWasConfirmed::class);

    expect(Storyfeed::registeredVerbs()['confirm'])->toBe(ActivityType::Update);
});

it('refuses a line and a class that disagree on the verb', function () {
    Story::for(Delivery::class)->verb('ship', DeliveryWasConfirmed::class);

    Storyfeed::compiledStories();
})->throws(StoryMisconfigured::class, 'to the verb [ship], but the class declares [confirm]');

it('refuses a line and a class that disagree on the type', function () {
    Story::for(Customer::class)->verb('confirm', DeliveryWasConfirmed::class);

    Storyfeed::compiledStories();
})->throws(StoryMisconfigured::class, 'for [customer], but the class declares [delivery]');

it('refuses a resource class, at the line, pointing at Story::resource()', function () {
    Story::for(Delivery::class)->verb('ship', DeliveryStory::class);
})->throws(StoryMisconfigured::class, 'A resource Story class is bound with Story::resource(Order::class, '.DeliveryStory::class.'::class)');

it('shows the bound class in the Action column, and the line as its source', function () {
    Story::for(Delivery::class)->verb('dispatch', DeliveryWasDispatched::class);

    Artisan::call('storyfeed:list', ['--json' => true]);

    $row = collect(json_decode(Artisan::output(), true))->firstWhere('verb', 'dispatch');

    expect($row['action'])->toBe(DeliveryWasDispatched::class)
        ->and($row['source'])->toContain('CallSitesTest.php:');
});

// ── A verb defined in two places ────────────────────────────────────────

it('conflicts when a resource action and a bound class define one verb, naming both', function () {
    Story::resource(Delivery::class, DeliveryStory::class);
    Story::for(Delivery::class)->verb('ship', DeliveryWasDispatched::class);

    expect(fn () => Storyfeed::compiledStories())
        ->toThrow(StoryMisconfigured::class, DeliveryStory::class.'@ship')
        ->and(fn () => Storyfeed::compiledStories())
        ->toThrow(StoryMisconfigured::class, 'CallSitesTest.php:');
});

it('conflicts when a line adds to a resource action\'s verb, even saying something else', function () {
    Story::resource(Delivery::class, DeliveryStory::class);
    Story::for(Delivery::class)->verb('ship')->keepFor('P30D');

    expect(fn () => Storyfeed::compiledStories())
        ->toThrow(StoryMisconfigured::class, '[delivery.ship] is defined twice: '.DeliveryStory::class.'@ship and ');
});

it('conflicts when a line adds to a bound class\'s verb', function () {
    Story::for(Delivery::class)->verb('icon_only')->intent('success');
    Story::for(Delivery::class)->verb('icon_only', DeliveryWasDispatched::class);

    expect(fn () => Storyfeed::compiledStories())
        ->toThrow(StoryMisconfigured::class, 'A verb a Story class defines is defined there whole');
});

it('conflicts when a class is registered and also bound', function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);
    Story::for(Delivery::class)->verb('confirm', DeliveryWasConfirmed::class);

    expect(fn () => Storyfeed::compiledStories())
        ->toThrow(StoryMisconfigured::class, '[delivery.confirm] is defined twice: '.DeliveryWasConfirmed::class.' and ');
});

it('still lets lines share a verb, each saying its own part', function () {
    Story::for(Delivery::class)->verb('ship')->headline(':actor shipped :object');
    Story::for(Delivery::class)->verb('ship')->icon('send');

    expect(Storyfeed::template('delivery', 'ship'))->toBe(':actor shipped :object')
        ->and(Storyfeed::icon('delivery', 'ship'))->toBe('send');
});
