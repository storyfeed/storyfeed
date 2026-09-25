<?php

use Illuminate\Support\Facades\Artisan;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\Story as Message;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Stories\Verb;
use Storyfeed\Tests\Fixtures\Stories\ConfirmAnything;
use Storyfeed\Tests\Fixtures\Stories\DeliveryStory;
use Storyfeed\Tests\Fixtures\Stories\ShipDelivery;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * An invokable story class, as an invokable controller: one verb's
 * declaration, grown too large for a line in feed.php. It extends nothing,
 * declares `__invoke(Verb $verb)`, and is bound with the same line as a
 * message class. The class's shape tells the two apart, as the router
 * checks for `__invoke`. Call sites never touch it.
 */

beforeEach(function () {
    ShipDelivery::$runs = 0;
});

afterEach(function () {
    app(StoryManifest::class)->delete();
});

it('binds an invokable class to a verb for a type', function () {
    Story::for(Delivery::class)->verb('ship', ShipDelivery::class);

    expect(Storyfeed::template('delivery', 'ship'))->toBe(':actor shipped :object')
        ->and(Storyfeed::actorlessTemplate('delivery', 'ship'))->toBe(':object was shipped')
        ->and(Storyfeed::icon('delivery', 'ship'))->toBe('send')
        ->and(Storyfeed::storyActions())->toHaveKey('delivery.ship', ShipDelivery::class.'@__invoke');
});

it('binds inside a group, and takes middleware on the line', function () {
    Story::for(Delivery::class)->group(function () {
        Story::verb('ship', ShipDelivery::class)->unbatched();
    });

    expect(Storyfeed::template('delivery', 'ship'))->toBe(':actor shipped :object')
        ->and(Storyfeed::middleware('delivery', 'ship'))->not->toContain('batch');
});

it('binds a verb for every type, with a group headline that names no type', function () {
    Story::verb('confirm', ConfirmAnything::class);

    expect(Storyfeed::template('delivery', 'confirm'))->toBe(':actor confirmed :object')
        ->and(Storyfeed::template('customer', 'confirm'))->toBe(':actor confirmed :object')
        ->and(Storyfeed::registeredGrammar())->toHaveKey('*.confirm')
        ->and(Storyfeed::registeredAggregateGrammar())->toHaveKey('actors.confirm', ':actors confirmed :count things');
});

it('refuses a byActors headline from a class bound to one type, as a resource class does', function () {
    Story::for(Delivery::class)->verb('confirm', ConfirmAnything::class);

    Storyfeed::compiledStories();
})->throws(StoryMisconfigured::class, ConfirmAnything::class.'::__invoke() gives its Group::byActors() grouping a headline');

it('accepts the three return forms an action does', function (object $class, string $headline) {
    Story::for(Delivery::class)->verb('ship', $class::class);

    expect(Storyfeed::template('delivery', 'ship'))->toBe($headline);
})->with([
    'Verb' => [new ShipDelivery, ':actor shipped :object'],
    'string' => [new class
    {
        public function __invoke(): string
        {
            return ':actor sent :object';
        }
    }, ':actor sent :object'],
    'array' => [new class
    {
        public function __invoke(): array
        {
            return ['headline' => ':actor dispatched :object', 'icon' => 'truck'];
        }
    }, ':actor dispatched :object'],
]);

it('holds __invoke to the rules of an action', function () {
    $class = new class
    {
        public function __invoke(Verb $verb): int
        {
            return 1;
        }
    };

    Story::for(Delivery::class)->verb('ship', $class::class);

    Storyfeed::compiledStories();
})->throws(StoryMisconfigured::class, "@__invoke] is an invokable story class's action, and it returns [int]");

it('refuses a class that is both a message and invokable, at the line', function () {
    $class = new class extends Message
    {
        public function headline(): string
        {
            return ':actor shipped :object';
        }

        public function toFeedActivity(): ?PendingActivity
        {
            return null;
        }

        public function __invoke(Verb $verb): Verb
        {
            return $verb;
        }
    };

    expect(fn () => Story::for(Delivery::class)->verb('ship', $class::class))
        ->toThrow(StoryMisconfigured::class, 'which is both a message class');
});

it('refuses a class that is neither, naming both shapes', function () {
    expect(fn () => Story::verb('ship', DeliveryStory::class))
        ->toThrow(StoryMisconfigured::class, 'is neither a message class (one that extends Storyfeed\Stories\Story) nor an invokable one (one with a public __invoke(Verb $verb) method). A resource Story class is bound with Story::resource(');
});

it('publishes through story(), never constructing the class at the call site', function () {
    Story::for(Delivery::class)->verb('ship', ShipDelivery::class)->name('delivery.ship');
    Story::verb('confirm', ConfirmAnything::class)->name('confirm');
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    Storyfeed::compileStories();
    $runs = ShipDelivery::$runs;

    $shipped = story('delivery.ship', Delivery::create(['tracking_number' => 'TN-1']))->by($user)->publish();
    $confirmed = story('confirm', Customer::create(['name' => 'Ada']))->by($user)->publish();

    expect($shipped->verb)->toBe('ship')
        ->and($confirmed->verb)->toBe('confirm')
        ->and(ShipDelivery::$runs)->toBe($runs);
});

it('is not a message class to the publisher', function () {
    Story::for(Delivery::class)->verb('ship', ShipDelivery::class);

    expect(Storyfeed::hasStory(ShipDelivery::class))->toBeFalse();
});

it('round-trips through storyfeed:cache', function () {
    Story::for(Delivery::class)->verb('ship', ShipDelivery::class);
    Story::verb('confirm', ConfirmAnything::class);

    $this->artisan('storyfeed:cache')->assertSuccessful();

    $manifest = require app(StoryManifest::class)->path();

    expect($manifest['actions']['delivery.ship'])->toBe(['uses' => ShipDelivery::class.'@__invoke', 'request' => false, 'parts' => null])
        ->and($manifest['stories'] ?? [])->not->toContain(ShipDelivery::class)
        ->and(Storyfeed::doctor(['manifest'])->findings)->toBe([]);

    app()->forgetInstance(Storyfeed\StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();

    expect(Storyfeed::template('delivery', 'ship'))->toBe(':actor shipped :object')
        ->and(Storyfeed::registeredAggregateGrammar())->toHaveKey('actors.confirm');
});

it('lists the class in the action column, as route:list shows an invokable controller', function () {
    Story::for(Delivery::class)->verb('ship', ShipDelivery::class);

    Artisan::call('storyfeed:list', ['--json' => true]);

    $rows = collect(json_decode(Artisan::output(), true))->keyBy('verb');

    expect($rows['ship']['action'])->toBe(ShipDelivery::class);

    Artisan::call('storyfeed:list');

    expect(Artisan::output())->toContain('ShipDelivery')->not->toContain('__invoke');
});
