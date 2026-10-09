<?php

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Exceptions;
use Storyfeed\Exceptions\DottedVerb;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Middleware\Batch;
use Storyfeed\Stories\BoundStory;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Stories\Verb;
use Storyfeed\StoryfeedManager;
use Storyfeed\Tests\Fixtures\Stories\DeliveryStory;
use Storyfeed\Tests\Fixtures\Stories\DeliveryWasDispatched;
use Storyfeed\Tests\Fixtures\Stories\RefundStory;
use Storyfeed\Tests\Fixtures\Stories\ShipDelivery;
use Storyfeed\Tests\Fixtures\Stories\VaryingStory;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

class BasicDeliveryStory
{
    public function place(Verb $verb, Request $request, BasicStoryLabel $label): Verb
    {
        return $verb->headline($label->headline())->actor($request->input('provider'))->icon('truck');
    }

    public function confirm(): string
    {
        return ':actor confirmed :object';
    }

    protected function hidden(): string
    {
        return 'hidden';
    }

    private function secret(): string
    {
        return 'secret';
    }

    public static function helper(): string
    {
        return 'static';
    }
}

class BasicStoryLabel
{
    public function headline(): string
    {
        return ':actor placed :object';
    }
}

afterEach(function () {
    app(StoryManifest::class)->delete();
});

it('registers a public method in each verb shape and returns the binding', function (string $shape) {
    $action = [BasicDeliveryStory::class, 'place'];
    $bound = match ($shape) {
        'scoped' => Story::for(Delivery::class)->verb('place', $action),
        'unscoped' => Story::verb('place', $action),
        'group' => Story::for(Delivery::class)->group(fn () => Story::verb('place', $action)),
    };
    // group() returns the group; the direct shapes return a BoundStory.
    if ($shape !== 'group') {
        expect($bound)->toBeInstanceOf(BoundStory::class)->and($bound->isMessage())->toBeFalse();
        $bound->name('checkout.place')->unbatched();
    }

    expect(Storyfeed::template('delivery', 'place'))->toBe(':actor placed :object')
        ->and(Storyfeed::storyActions())->toHaveKey(($shape === 'unscoped' ? '*' : 'delivery').'.place', BasicDeliveryStory::class.'@place')
        ->and(Storyfeed::hasStory(BasicDeliveryStory::class))->toBeFalse();

    if ($shape === 'unscoped') {
        expect(Storyfeed::template('customer', 'place'))->toBe(':actor placed :object');
    }
    if ($shape !== 'group') {
        expect(Storyfeed::namedStory('checkout.place'))->toBe(($shape === 'unscoped' ? '*' : 'delivery').'.place')
            ->and(Storyfeed::middleware('delivery', 'place'))->not->toContain(Batch::class);
    }
})->with(['scoped', 'unscoped', 'group']);

it('supports string and Verb returns by the resource contract', function () {
    Story::for(Delivery::class)->verb('approve', [BasicDeliveryStory::class, 'confirm']);
    Story::for(Delivery::class)->verb('send', [DeliveryStory::class, 'ship']);

    expect(Storyfeed::template('delivery', 'approve'))->toBe(':actor confirmed :object')
        ->and(Storyfeed::template('delivery', 'send'))->toBe(':actor shipped :object')
        ->and(Storyfeed::icon('delivery', 'send'))->toBe('send');
});

it('inherits group middleware and name prefixes', function () {
    Story::as('checkout.')->middleware('batch')->for(Delivery::class)->group(function () {
        Story::verb('place', [BasicDeliveryStory::class, 'place'])->name('place')->withoutMiddleware('batch');
    });

    expect(Storyfeed::namedStory('checkout.place'))->toBe('delivery.place')
        ->and(Storyfeed::middleware('delivery', 'place'))->not->toContain(Batch::class);
});

it('rejects invalid action targets at registration', function (array $action, string $target, string $reason) {
    expect(fn () => Story::verb('place', $action))->toThrow(InvalidArgumentException::class, $target)
        ->and(fn () => Story::verb('place', $action))->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'missing class' => [['App\\Stories\\MissingStory', 'place'], 'App\\Stories\\MissingStory::place', 'class does not exist'],
    'missing method' => [[BasicDeliveryStory::class, 'missing'], BasicDeliveryStory::class.'::missing', 'method does not exist'],
    'protected' => [[BasicDeliveryStory::class, 'hidden'], BasicDeliveryStory::class.'::hidden', 'not public'],
    'private' => [[BasicDeliveryStory::class, 'secret'], BasicDeliveryStory::class.'::secret', 'not public'],
    'static' => [[BasicDeliveryStory::class, 'helper'], BasicDeliveryStory::class.'::helper', 'static'],
    'message' => [[DeliveryWasDispatched::class, 'headline'], 'DeliveryWasDispatched::headline', 'message class'],
    'one element' => [[BasicDeliveryStory::class], BasicDeliveryStory::class, 'exactly'],
    'three elements' => [[BasicDeliveryStory::class, 'place', 'extra'], BasicDeliveryStory::class.'::place', 'exactly'],
    'object' => [[new BasicDeliveryStory, 'place'], 'BasicDeliveryStory::place', 'exactly'],
    'non-string method' => [[BasicDeliveryStory::class, 1], BasicDeliveryStory::class.'::int', 'exactly'],
    'associative' => [['class' => BasicDeliveryStory::class, 'method' => 'place'], BasicDeliveryStory::class.'::place', 'exactly'],
    'empty' => [[], 'expected exactly', 'exactly'],
]);

it('holds a bound method to resource return and request contracts', function (object $class, string $reason) {
    expect(function () use ($class) {
        Story::verb('place', [$class::class, 'place']);
        Storyfeed::compiledStories();
    })->toThrow(StoryMisconfigured::class, $reason);
})->with([
    'invalid return' => [new class
    {
        public function place(): int
        {
            return 1;
        }
    }, 'returns [int]'],
    'another verb' => [new class
    {
        public function place(): Verb
        {
            return Verb::for('*', 'place');
        }
    }, 'returned a Verb it was not given'],
    'form request' => [new class
    {
        public function place(Verb $verb, FormRequest $request): Verb
        {
            return $verb;
        }
    }, 'An action takes Illuminate\\Http\\Request'],
]);

it('uses a blank request at compile and the live request at each publish', function () {
    app()->instance('request', Request::create('/', 'POST', ['provider' => 'Compile']));
    RefundStory::$seen = [];
    Story::for(Delivery::class)->verb('refund', [RefundStory::class, 'refund']);
    Storyfeed::compileStories();
    expect(RefundStory::$seen)->toBe([null]);

    $delivery = Delivery::create(['tracking_number' => 'T1']);
    foreach (['Stripe', 'Paddle'] as $provider) {
        app()->instance('request', Request::create('/', 'POST', ['provider' => $provider]));
        expect(Storyfeed::activity('refund', $delivery)->publish()->actor->name)->toBe($provider);
    }

    expect(RefundStory::$seen)->toBe([null, 'Stripe', 'Paddle']);
});

it('preserves strict grammar checks for request actions', function (bool $strict) {
    config(['storyfeed.grammar.strict' => $strict]);
    Exceptions::fake();
    Story::for(Delivery::class)->verb('place', [VaryingStory::class, 'place']);
    Storyfeed::compileStories();
    app()->instance('request', Request::create('/', 'POST', ['rush' => 1]));
    $delivery = Delivery::create(['tracking_number' => 'T1']);

    if ($strict) {
        expect(fn () => Storyfeed::activity('place', $delivery)->publish())->toThrow(StoryMisconfigured::class, 'different headline');
    } else {
        Storyfeed::activity('place', $delivery)->publish();
        Storyfeed::activity('place', $delivery)->publish();
        Exceptions::assertReportedCount(1);
        expect(Storyfeed::template('delivery', 'place'))->toBe(':actor placed :object');
    }
})->with([true, false]);

it('round-trips a named request action through cache and publishes from it', function () {
    Story::for(Delivery::class)->verb('place', [BasicDeliveryStory::class, 'place'])->name('checkout.place')->unbatched();
    $this->artisan('storyfeed:cache')->assertSuccessful();
    $manifest = require app(StoryManifest::class)->path();
    expect($manifest['actions']['delivery.place']['uses'])->toBe(BasicDeliveryStory::class.'@place')
        ->and($manifest['actions']['delivery.place']['request'])->toBeTrue()
        ->and(Storyfeed::doctor(['manifest'])->findings)->toBe([]);

    app()->forgetInstance(StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();
    app(StoryManifest::class)->apply(app(StoryfeedManager::class));
    app()->instance('request', Request::create('/', 'POST', ['provider' => 'Stripe']));
    $delivery = Delivery::create(['tracking_number' => 'T1']);
    expect(story('checkout.place', $delivery)->publish()->actor->name)->toBe('Stripe')
        ->and(Storyfeed::template('delivery', 'place'))->toBe(':actor placed :object')
        ->and(Storyfeed::middleware('delivery', 'place'))->not->toContain(Batch::class);
});

it('lists the class and method in the action column', function () {
    Story::for(Delivery::class)->verb('place', [BasicDeliveryStory::class, 'place']);
    Artisan::call('storyfeed:list', ['--json' => true]);
    $row = collect(json_decode(Artisan::output(), true))->firstWhere('verb', 'place');
    expect($row['action'])->toBe(BasicDeliveryStory::class.'@place');
    Artisan::call('storyfeed:list');
    expect(Artisan::output())->toContain('BasicDeliveryStory@place');
});

it('lets a later explicit action replace a resource for just the matching type and verb', function (string $shape) {
    Story::resource([Delivery::class, Customer::class], DeliveryStory::class)->only('ship', 'create')->middleware('batch');
    $action = $shape === 'array' ? [DeliveryStory::class, 'ship'] : ShipDelivery::class;
    Story::for(Delivery::class)->verb('ship', $action)->name('dispatch')->unbatched();

    $compiled = Storyfeed::compiledStories();
    expect($compiled['actions']['delivery.ship']['uses'])->toBe($shape === 'array' ? DeliveryStory::class.'@ship' : ShipDelivery::class.'@__invoke')
        ->and($compiled['actions']['customer.ship']['uses'])->toBe(DeliveryStory::class.'@ship')
        ->and($compiled['names'])->toHaveKey('dispatch', 'delivery.ship')->toHaveKey('customer.ship', 'customer.ship')->not->toHaveKey('delivery.ship')
        ->and($compiled['grammar'])->toHaveKeys(['delivery.create', 'customer.create'])
        ->and(Storyfeed::middleware('delivery', 'ship'))->not->toContain(Batch::class)
        ->and(Storyfeed::middleware('customer', 'ship'))->toContain(Batch::class);
    $this->artisan('storyfeed:cache')->assertSuccessful();
})->with(['array', 'invokable']);

it('replaces an earlier action whole rather than retaining its presentation fields', function () {
    Story::for(Delivery::class)->verb('place', [BasicDeliveryStory::class, 'place'])->name('earlier');
    Storyfeed::compileStories();
    Story::for(Delivery::class)->verb('place', [BasicDeliveryStory::class, 'confirm'])->name('later');

    $compiled = Storyfeed::compiledStories();
    expect(Storyfeed::template('delivery', 'place'))->toBe(':actor confirmed :object')
        ->and($compiled['icons'])->not->toHaveKey('delivery.place')
        ->and($compiled['names'])->toBe(['later' => 'delivery.place']);
});

class LegacyMethodStory
{
    public function reopen(): string
    {
        return ':actor reopened :object';
    }

    public function optIn(): string
    {
        return ':actor opted in to :object';
    }

    public function edgeCase(): string
    {
        return ':actor exercised :object';
    }
}

it('preserves a verb with no resource method spelling through compile cache and list', function (string $verb, string $method, string $headline) {
    Story::for(Delivery::class)->verb($verb, [LegacyMethodStory::class, $method]);
    $key = 'delivery.'.$verb;
    $uses = LegacyMethodStory::class.'@'.$method;
    $compiled = Storyfeed::compiledStories();

    expect($compiled['actions'])->toHaveKey($key)
        ->and($compiled['verbs'])->toHaveKey($verb)
        ->and($compiled['grammar'])->toBe([$key => $headline])
        ->and($compiled['actions'][$key]['uses'])->toBe($uses);

    $this->artisan('storyfeed:cache')->assertSuccessful();
    $manifest = require app(StoryManifest::class)->path();
    expect($manifest['actions'])->toHaveKey($key)
        ->and($manifest['verbs'])->toHaveKey($verb)
        ->and($manifest['grammar'])->toBe([$key => $headline]);

    Artisan::call('storyfeed:list', ['--json' => true]);
    $rows = json_decode(Artisan::output(), true);
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['verb'])->toBe($verb)
        ->and($rows[0]['action'])->toBe($uses);
    Artisan::call('storyfeed:list');
    expect(Artisan::output())->toContain($verb)->toContain($uses);

    app()->forgetInstance(StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();
    app(StoryManifest::class)->apply(app(StoryfeedManager::class));
    expect(Storyfeed::template('delivery', $verb))->toBe($headline)
        ->and(Storyfeed::storyActions())->toHaveKey($key, $uses);
})->with([
    'hyphen' => ['re-open', 'reopen', ':actor reopened :object'],
    'legacy opt-in' => ['opt-in', 'optIn', ':actor opted in to :object'],
    'neutral digit edge case' => ['1_edge_case', 'edgeCase', ':actor exercised :object'],
]);

it('still rejects dots in explicitly bound verbs', function () {
    expect(function () {
        Story::for(Delivery::class)->verb('a.b', [LegacyMethodStory::class, 'reopen']);
        Storyfeed::compiledStories();
    })->toThrow(DottedVerb::class, 'Verb [a.b] may not contain a dot');
});
