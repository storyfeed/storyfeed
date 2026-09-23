<?php

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Artisan;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Stories\Verb;
use Storyfeed\Tests\Fixtures\Stories\DeliveryStory;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Story::resource(Order::class, OrderStory::class): a resource Story class,
 * read the way a resource controller is. Every public method is a verb; the
 * class extends nothing, and nothing calls it but the compiler.
 */

beforeEach(function () {
    DeliveryStory::$runs = 0;
});

afterEach(function () {
    app(StoryManifest::class)->delete();
});

it('makes every public method a verb, snake-cased', function () {
    Story::resource(Delivery::class, DeliveryStory::class);

    expect(Storyfeed::template('delivery', 'confirm_payment'))->toBe(':actor confirmed payment for :object')
        ->and(Storyfeed::template('delivery', 'ship'))->toBe(':actor shipped :object')
        ->and(Storyfeed::icon('delivery', 'ship'))->toBe('send')
        ->and(Storyfeed::declaredVerb('confirm_payment'))->toBeTrue()
        // Never a verb: static, protected, and nothing named confirmPayment.
        ->and(Storyfeed::registeredGrammar())->not->toHaveKeys(['delivery.helper_on_the_class', 'delivery.label', 'delivery.confirmPayment']);
});

it('lets a resource class have the verbs a base class would reserve', function () {
    Story::resource(Delivery::class, DeliveryStory::class);

    expect(Storyfeed::template('delivery', 'publish'))->toBe(':actor published :object')
        ->and(Storyfeed::template('delivery', 'record'))->toBe(':actor recorded :object');
});

it('replaces a conventional verb whole with its method, and keeps the defaults it has none for', function () {
    Story::resource(Delivery::class, DeliveryStory::class);

    expect(Storyfeed::template('delivery', 'create'))->toBe(':actor booked :object')
        ->and(Storyfeed::icon('delivery', 'create'))->toBe('truck')
        // Replaced whole: the default anonymous headline went with it.
        ->and(Storyfeed::actorlessTemplate('delivery', 'create'))->toBeNull()
        ->and(Storyfeed::template('delivery', 'update'))->toBe(':actor updated :object')
        ->and(Storyfeed::actorlessTemplate('delivery', 'delete'))->toBe(':object was deleted');
});

it('runs each action once per compile, never per publish', function () {
    Story::resource(Delivery::class, DeliveryStory::class);
    $user = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);

    Storyfeed::compileStories();
    $compiled = DeliveryStory::$runs;

    Storyfeed::activity()->actor($user)->verb('create', Delivery::create(['tracking_number' => 'T1']))->publish();
    Storyfeed::activity()->actor($user)->verb('create', Delivery::create(['tracking_number' => 'T2']))->publish();

    expect($compiled)->toBe(1)
        ->and(DeliveryStory::$runs)->toBe(1);
});

it('narrows with only() and except(), naming verbs as stored', function () {
    Story::resource(Delivery::class, DeliveryStory::class)->only('confirm_payment', 'update');
    Story::resource('customer', DeliveryStory::class)->except(['delete', 'restore', 'confirm_payment']);

    expect(Storyfeed::registeredGrammar())->toHaveKeys(['delivery.confirm_payment', 'delivery.update', 'customer.ship', 'customer.create'])
        ->and(Storyfeed::registeredGrammar())->not->toHaveKeys(['delivery.ship', 'delivery.create', 'customer.confirm_payment', 'customer.delete']);
});

it('rejects a verb the class and the conventions both lack, once the actions are read', function () {
    Story::resource(Delivery::class, DeliveryStory::class)->only('confirmPayment');

    Storyfeed::compiledStories();
})->throws(StoryMisconfigured::class, 'has no [confirmPayment] verb. It defines create, update, delete, restore, confirm_payment');

it('fails on a public method with another return type, naming it', function () {
    $class = new class
    {
        public function create(Verb $verb): Verb
        {
            return $verb->headline(':actor created :object');
        }

        public function total(): int
        {
            return 3;
        }
    };

    Story::resource(Delivery::class, $class::class);

    expect(fn () => Storyfeed::compiledStories())->toThrow(
        StoryMisconfigured::class,
        '@total] is a public method of a resource Story class, so it is an action, and it returns [int]',
    );
});

it('fails on an action with no return type, or a nullable one', function (object $class, string $message) {
    Story::resource(Delivery::class, $class::class);

    expect(fn () => Storyfeed::compiledStories())->toThrow(StoryMisconfigured::class, $message);
})->with([
    'untyped' => [new class
    {
        public function create(Verb $verb)
        {
            return $verb;
        }
    }, 'declares no return type'],
    'nullable' => [new class
    {
        public function create(): ?string
        {
            return null;
        }
    }, 'returns [?string]'],
]);

it('fails on an action that returns a Verb it was not given', function () {
    $class = new class
    {
        public function create(Verb $verb): Verb
        {
            return Verb::make('delivery.create');
        }
    };

    Story::resource(Delivery::class, $class::class);

    expect(fn () => Storyfeed::compiledStories())->toThrow(StoryMisconfigured::class, 'returned a Verb it was not given');
});

it('fails on two methods that store the same verb', function () {
    $class = new class
    {
        public function confirmPayment(): string
        {
            return ':actor confirmed :object';
        }

        public function confirm_payment(): string
        {
            return ':actor confirmed :object';
        }
    };

    Story::resource(Delivery::class, $class::class);

    expect(fn () => Storyfeed::compiledStories())->toThrow(StoryMisconfigured::class, 'two actions for the verb [confirm_payment]');
});

it('fails on an action that takes a form request', function () {
    $class = new class
    {
        public function create(Verb $verb, FormRequest $request): Verb
        {
            return $verb;
        }
    };

    Story::resource(Delivery::class, $class::class);

    expect(fn () => Storyfeed::compiledStories())->toThrow(StoryMisconfigured::class, 'An action takes Illuminate\Http\Request');
});

it('fails on a fixed model actor, which no manifest could hold', function () {
    $class = new class
    {
        public function create(Verb $verb): Verb
        {
            return $verb->headline(':actor created :object')->actor(new User);
        }
    };

    Story::resource(Delivery::class, $class::class);

    expect(fn () => Storyfeed::compiledStories())->toThrow(StoryMisconfigured::class, 'A fixed actor is a party name');
});

it('fails on a class that does not exist', function () {
    Story::resource(Delivery::class, 'App\Stories\NoSuchStory');
})->throws(StoryMisconfigured::class, 'binds [App\Stories\NoSuchStory], which is not a class');

it('conflicts with the same verb defined in the file, naming the action and the line', function () {
    Story::resource(Delivery::class, DeliveryStory::class);
    Story::for(Delivery::class)->verb('ship')->headline(':actor sent :object');

    expect(fn () => Storyfeed::compiledStories())->toThrow(StoryMisconfigured::class, DeliveryStory::class.'@ship');
});

it('lists each verb with the action it came from', function () {
    Story::resource(Delivery::class, DeliveryStory::class)->only('confirm_payment', 'update');

    Artisan::call('storyfeed:list', ['--json' => true]);

    $rows = collect(json_decode(Artisan::output(), true))->keyBy('verb');

    expect($rows['confirm_payment']['action'])->toBe(DeliveryStory::class.'@confirmPayment')
        ->and($rows['update']['action'])->toBeNull();

    Artisan::call('storyfeed:list');

    expect(Artisan::output())->toContain('Action')->toContain('DeliveryStory@confirmPayment');
});

it('stores verb → Class@method in the manifest, so nothing reflects again', function () {
    Story::resource(Delivery::class, DeliveryStory::class);

    $this->artisan('storyfeed:cache')->assertSuccessful();

    $manifest = require app(StoryManifest::class)->path();

    expect($manifest['actions']['delivery.confirm_payment'])->toBe([
        'uses' => DeliveryStory::class.'@confirmPayment',
        'request' => false,
        'parts' => null,
    ])->and(Storyfeed::storyActions())->toHaveKey('delivery.ship', DeliveryStory::class.'@ship');
});

it('carries a missing headline from the array form', function () {
    Story::resource(Delivery::class, DeliveryStory::class);

    expect(Storyfeed::missingTemplate('delivery', 'ship'))->toBe(':actor shipped a delivery since removed');
});

it('reports a cached manifest as current, then stale once an action changes', function () {
    Story::resource(Delivery::class, DeliveryStory::class);
    $this->artisan('storyfeed:cache')->assertSuccessful();

    expect(Storyfeed::doctor(['manifest'])->findings)->toBe([]);

    Story::resource('customer', DeliveryStory::class)->only('ship');

    expect(Storyfeed::doctor(['manifest'])->withCode('manifest.stale')->first()->message)->toContain('actions[customer.ship]');
});
