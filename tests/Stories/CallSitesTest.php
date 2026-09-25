<?php

use Illuminate\Support\Facades\Artisan;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Exceptions\StoryNotFound;
use Storyfeed\Exceptions\StoryObjectMismatch;
use Storyfeed\Exceptions\UnknownStory;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\BoundStory;
use Storyfeed\Stories\Story as Message;
use Storyfeed\Tests\Fixtures\Stories\DeliveryStory;
use Storyfeed\Tests\Fixtures\Stories\DeliveryWasDispatched;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveryWasConfirmed;

/*
 * Call sites, read the way Laravel reads routes: a story's name is the
 * public handle, as a route's name is, and `story('delivery.ship', $order)`
 * is the feed's `route('orders.ship', $order)`. A resource Story class is a declaration
 * call sites never touch. A message class is a Notification: bound to its
 * verb in routes/feed.php, constructed with its data at the call site and
 * published, `Storyfeed::publish(new OrderWasShipped($order))`.
 */

// ── story() ─────────────────────────────────────────────────────────────

it('publishes a story by name, as route() names a route', function () {
    Story::for(Delivery::class)->verb('ship')->name('delivery.ship');
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    $activity = story('delivery.ship', $delivery)->by($user)->publish();

    expect($activity->verb)->toBe('ship')
        ->and($activity->object_id)->toEqual($delivery->id)
        ->and($activity->actor_id)->toEqual($user->id);
});

it('takes no object, or a string-backed enum case, as route() does', function () {
    Story::verb('ping')->name('ping');
    Story::verb(ActivityVerb::Confirm)->name('confirm');

    $pending = story(ActivityVerb::Confirm);

    expect($pending)->toBeInstanceOf(PendingActivity::class)
        ->and(story('ping')->publish()->verb)->toBe('ping')
        ->and($pending->object(Delivery::create(['tracking_number' => 'TN-1']))->publish()->verb)->toBe('confirm');
});

it('throws for a name nothing defined, whatever verbs.strict says, as route() does', function () {
    config(['storyfeed.verbs.strict' => false]);
    Story::for(Delivery::class)->verb('ship');

    expect(fn () => story('ship'))->toThrow(StoryNotFound::class, 'Story [ship] not defined.')
        ->and(fn () => Storyfeed::route('delivery.shp'))->toThrow(StoryNotFound::class, 'Story [delivery.shp] not defined.')
        ->and(Activity::count())->toBe(0);
});

it('throws for an object of another type than the name\'s', function () {
    Story::for(Delivery::class)->verb('ship')->name('delivery.ship');

    expect(fn () => story('delivery.ship', Customer::create(['name' => 'Acme'])))
        ->toThrow(StoryObjectMismatch::class, 'Wrong object for [Story: delivery.ship] [Key: delivery.ship] [Given: customer].')
        ->and(fn () => story('delivery.ship', 'Stripe'))
        ->toThrow(StoryObjectMismatch::class, '[Given: storyfeed.party]');
});

it('takes any object for a name defined for every type', function () {
    Story::verb('confirm')->name('confirm');

    expect(story('confirm', Customer::create(['name' => 'Acme']))->publish()->object_type)->toBe('customer')
        ->and(story('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish()->object_type)->toBe('delivery');
});

it('is Storyfeed::route() on the facade, as URL::route() is route()', function () {
    Story::for(Delivery::class)->verb('ship')->name('delivery.ship');

    $activity = Storyfeed::route('delivery.ship', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    expect($activity->verb)->toBe('ship');
});

it('refuses a verb as a name, pointing nowhere near a guess', function () {
    Story::for(Delivery::class)->verb('ship')->name('delivery.ship');

    // `ship` is the verb, not the name: story() never guesses which it is.
    story('ship', Delivery::create(['tracking_number' => 'TN-1']));
})->throws(StoryNotFound::class);

it('leaves an app that already defines story() alone', function () {
    $helper = new ReflectionFunction('story');

    expect(realpath((string) $helper->getFileName()))->toBe(realpath(__DIR__.'/../../src/helpers.php'))
        ->and(file_get_contents((string) $helper->getFileName()))->toContain("if (! function_exists('story')) {");
});

// ── Storyfeed::publish(new Message(…)) ──────────────────────────────────

it('publishes a message, and publishNow() does the same', function () {
    Story::for(Delivery::class)->verb('dispatch', DeliveryWasDispatched::class);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    $published = Storyfeed::publish(new DeliveryWasDispatched($delivery));
    $now = Storyfeed::publishNow(new DeliveryWasDispatched($delivery));

    expect($published?->verb)->toBe('dispatch')
        ->and($published?->object_id)->toEqual($delivery->id)
        ->and($now?->verb)->toBe('dispatch');
});

it('publishes nothing when the message says nothing happened', function () {
    Story::for(Delivery::class)->verb('dispatch', QuietDispatch::class);

    expect(Storyfeed::publish(new QuietDispatch(Delivery::create(['tracking_number' => 'TN-1']))))->toBeNull()
        ->and(Storyfeed::publishNow(new QuietDispatch(Delivery::create(['tracking_number' => 'TN-2']))))->toBeNull()
        ->and(Activity::count())->toBe(0);
});

it('is the event hook, so a message dispatched as an event publishes too', function () {
    Story::for(Delivery::class)->verb('dispatch', DeliveryWasDispatched::class);

    event(new DeliveryWasDispatched(Delivery::create(['tracking_number' => 'TN-1'])));

    expect(Activity::sole()->verb)->toBe('dispatch');
});

it('refuses to publish a message nothing registered', function () {
    Storyfeed::publish(new DeliveryWasDispatched(Delivery::create(['tracking_number' => 'TN-1'])));
})->throws(UnknownStory::class, 'is not registered');

it('refuses a name bound to a message, naming the class to construct', function () {
    Story::for(Delivery::class)->verb('dispatch', DeliveryWasDispatched::class)->name('delivery.dispatch');

    expect(fn () => story('delivery.dispatch'))
        ->toThrow(UnknownStory::class, 'Storyfeed::publish(new DeliveryWasDispatched(…))')
        ->and(fn () => story('delivery.dispatch', Delivery::create(['tracking_number' => 'TN-1'])))
        ->toThrow(UnknownStory::class, 'The story [delivery.dispatch] is bound to the message class ['.DeliveryWasDispatched::class.']');
});

it('lets story() name the verb for a type no message is bound to', function () {
    Story::for(Delivery::class)->verb('dispatch', DeliveryWasDispatched::class);
    Story::for(Customer::class)->verb('dispatch')->headline(':actor dispatched :object')->name('customer.dispatch');

    $customer = Customer::create(['name' => 'Acme']);

    expect(story('customer.dispatch', $customer)->publish()->verb)->toBe('dispatch');
});

it('drops the statics that made a one-verb class half controller, half message', function (string $method) {
    expect(method_exists(DeliveryWasConfirmed::class, $method) && (new ReflectionMethod(DeliveryWasConfirmed::class, $method))->isStatic())->toBeFalse();
})->with(['verb', 'of', 'objects', 'anonymous', 'record', 'publish']);

it('keeps activity() inside the class, and drops the enum\'s action() and activity()', function () {
    expect((new ReflectionMethod(DeliveryWasConfirmed::class, 'activity'))->isPublic())->toBeFalse()
        ->and(method_exists(PendingActivity::class, 'of'))->toBeFalse()
        ->and(method_exists(ActivityVerb::Confirm, 'action'))->toBeFalse()
        ->and(method_exists(ActivityVerb::Confirm, 'activity'))->toBeFalse();
});

it('says a message\'s activity() takes the object when given a Story class', function () {
    Story::for(Delivery::class)->verb('dispatch', ClassAsObject::class);

    // A string object is a party's name: without the guard, this would
    // publish an activity about a party called "Storyfeed\Tests\…".
    Storyfeed::publish(new ClassAsObject);
})->throws(UnknownStory::class, "ClassAsObject's \$this->activity() takes the activity's object");

// ── Story::for(…)->verb('x', OneVerbClass::class) ───────────────────────

it('binds a message class to its verb, and the class learns it', function () {
    Story::for(Delivery::class)->verb('dispatch', DeliveryWasDispatched::class);

    $activity = Storyfeed::publish(new DeliveryWasDispatched(Delivery::create(['tracking_number' => 'TN-1'])));

    expect($activity?->verb)->toBe('dispatch')
        ->and(Storyfeed::storyVerb(DeliveryWasDispatched::class))->toBe('dispatch')
        ->and(Storyfeed::template('delivery', 'dispatch'))->toBe(':actor dispatched :object')
        ->and(Storyfeed::icon('delivery', 'dispatch'))->toBe('truck')
        ->and(Storyfeed::hasStory(DeliveryWasDispatched::class))->toBeTrue();
});

it('binds inside a group with Story::verb(), and returns the binding from the scope', function () {
    Story::for(Delivery::class)->group(function () {
        Story::verb('dispatch', DeliveryWasDispatched::class);
    });

    // A binding comes back to be named or given middleware, as Story::verb()'s does.
    $bound = Story::for('customer')->verb('dispatch', DeliveryWasDispatched::class);
    Story::for('customer')->verb('offboard', fn ($verb) => $verb->headline(':actor offboarded :object'));

    expect($bound)->toBeInstanceOf(BoundStory::class);

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

it('conflicts when a class is bound twice to one verb', function () {
    Story::verb(ActivityVerb::Confirm, DeliveryWasConfirmed::class);
    Story::for(Delivery::class)->verb('confirm', DeliveryWasConfirmed::class);

    // Each binding is its line, as each route is.
    expect(fn () => Storyfeed::compiledStories())
        ->toThrow(StoryMisconfigured::class, '[delivery.confirm] is defined twice: '.__FILE__.':');
});

it('still lets lines share a verb, each saying its own part', function () {
    Story::for(Delivery::class)->verb('ship')->headline(':actor shipped :object');
    Story::for(Delivery::class)->verb('ship')->icon('send');

    expect(Storyfeed::template('delivery', 'ship'))->toBe(':actor shipped :object')
        ->and(Storyfeed::icon('delivery', 'ship'))->toBe('send');
});

class QuietDispatch extends Message
{
    public function __construct(public Delivery $delivery) {}

    public function toFeedActivity(): ?PendingActivity
    {
        return null;
    }

    public function headline(): string
    {
        return ':actor dispatched :object';
    }
}

class ClassAsObject extends Message
{
    public function toFeedActivity(): ?PendingActivity
    {
        return $this->activity(DeliveryWasConfirmed::class);
    }

    public function headline(): string
    {
        return ':actor dispatched :object';
    }
}
