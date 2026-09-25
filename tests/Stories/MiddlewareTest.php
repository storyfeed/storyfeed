<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Middleware\Batch;
use Storyfeed\Models\Activity;
use Storyfeed\PendingActivity;
use Storyfeed\Stories\BoundStory;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Stories\Verb;
use Storyfeed\Tests\Fixtures\Middleware\ActAs;
use Storyfeed\Tests\Fixtures\Middleware\InContext;
use Storyfeed\Tests\Fixtures\Middleware\Trace;
use Storyfeed\Tests\Fixtures\Stories\DeliveryWasSorted;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Story middleware: an Illuminate Pipeline around publish, registered and
 * attached under the router's names. What runs is the `default` group, then
 * the verb's own, minus what it excludes.
 */

beforeEach(function () {
    Trace::$calls = [];
    Story::aliasMiddleware('trace', Trace::class);
});

// A manifest left behind is booted by every later test in this worker.
afterEach(function () {
    app(StoryManifest::class)->delete();
});

function delivery(string $number = 'TN-1'): Delivery
{
    return Delivery::create(['tracking_number' => $number]);
}

describe('the pipeline', function () {
    it('wraps publish, and what runs after $next sees the stored row', function () {
        Story::verb('confirm')->middleware(['trace:outer', 'trace:inner']);

        $activity = Storyfeed::activity('confirm', delivery())->actor('Courier')->publish();

        expect($activity->exists)->toBeTrue()
            ->and(Trace::$calls)->toBe([
                'outer:before', 'inner:before',
                'inner:after:stored', 'outer:after:stored',
            ]);
    });

    it('runs the default group for a verb nothing defines', function () {
        Story::middlewareGroup('default', ['batch', 'trace:default']);

        Storyfeed::activity('ping')->actor('Courier')->publish();

        expect(Trace::$calls)->toBe(['default:before', 'default:after:stored']);
    });

    it('resolves what a verb runs: the default group, its own, minus exclusions, once each', function () {
        Story::middlewareGroup('audited', ['trace:audit']);
        Story::verb('confirm')->middleware(['audited', Trace::class.':audit', 'trace:own'])->withoutMiddleware('batch');
        Story::verb('ship')->withoutMiddleware('default');

        expect(Storyfeed::middleware(null, 'confirm'))->toBe([Trace::class.':audit', Trace::class.':own'])
            ->and(Storyfeed::middleware(null, 'ship'))->toBe([])
            ->and(Storyfeed::middleware(null, 'anything'))->toBe([Batch::class]);
    });

    it('leaves a parameterised middleware in place when its bare name is excluded, as the router does', function () {
        Story::verb('confirm')->middleware('batch:5 minutes')->withoutMiddleware('batch');

        expect(Storyfeed::middleware(null, 'confirm'))->toBe([Batch::class.':5 minutes']);
    });

    it('takes the most specific declaration on the type → verb ladder', function () {
        Story::for(Delivery::class)->fallback()->middleware('trace:type');
        Story::for(Delivery::class)->verb('confirm')->middleware('trace:verb');
        Story::for(Delivery::class)->verb('ship');

        expect(Storyfeed::middleware('delivery', 'confirm'))->toBe([Batch::class, Trace::class.':verb'])
            ->and(Storyfeed::middleware('delivery', 'ship'))->toBe([Batch::class, Trace::class.':type'])
            ->and(Storyfeed::middleware('customer', 'ship'))->toBe([Batch::class]);
    });

    it('runs closures', function () {
        Story::verb('confirm')->middleware(function (PendingActivity $activity, Closure $next) {
            $activity->data(['seen' => true]);

            return $next($activity);
        });

        expect(Storyfeed::activity('confirm', delivery())->publish()->data)->toBe(['seen' => true]);
    });

    it('refuses an object, which could not be listed or cached', function () {
        Story::verb('confirm')->middleware([new Trace]);
    })->throws(InvalidArgumentException::class, "can't be listed or cached");

    it('refuses a middleware that changes what the activity is', function () {
        Story::verb('confirm')->middleware(fn (PendingActivity $activity, Closure $next) => $next($activity->verb('ship')));

        Storyfeed::activity('confirm', delivery())->publish();
    })->throws(LogicException::class, 'may not change what an activity is');
});

describe('a short circuit', function () {
    it('publishes nothing and returns the unsaved activity when a middleware returns null', function () {
        Event::fake([ActivityPublished::class]);
        Story::verb('confirm')->middleware(['trace:outer', fn () => null]);

        $activity = Storyfeed::activity('confirm', delivery())->actor('Courier')->publish();

        expect($activity->exists)->toBeFalse()
            ->and($activity->uid)->not->toBeNull()
            ->and($activity->published_at)->not->toBeNull()
            ->and(Activity::query()->count())->toBe(0)
            ->and(Trace::$calls)->toBe(['outer:before', 'outer:after:unsaved']);

        Event::assertNotDispatched(ActivityPublished::class);
    });

    it('returns the Activity a middleware returns', function () {
        Story::verb('confirm')->middleware(fn (PendingActivity $activity) => $activity->activity);

        $activity = Storyfeed::activity('confirm', delivery())->publish();

        expect($activity)->toBeInstanceOf(Activity::class)
            ->and($activity->exists)->toBeFalse();
    });

    it('refuses anything else, naming the verb', function () {
        Story::verb('confirm')->middleware(fn () => 'nope');

        Storyfeed::activity('confirm', delivery())->publish();
    })->throws(UnexpectedValueException::class, 'Story middleware for [confirm] returned string');
});

describe('attaching', function () {
    it('puts a Story::middleware() group ahead of each definition\'s own, outermost first', function () {
        Story::middleware('trace:outer')->group(function () {
            Story::middleware(['trace:inner'])->group(function () {
                Story::for(Delivery::class)->verb('confirm')->middleware('trace:own');
            });

            Story::verb('ship');
        });

        Story::verb('return');

        expect(Storyfeed::middleware('delivery', 'confirm'))->toBe([Batch::class, Trace::class.':outer', Trace::class.':inner', Trace::class.':own'])
            ->and(Storyfeed::middleware(null, 'ship'))->toBe([Batch::class, Trace::class.':outer'])
            ->and(Storyfeed::middleware(null, 'return'))->toBe([Batch::class]);
    });

    it('does not call one group around two lines for a key a conflict', function () {
        Story::middleware('trace:audit')->group(function () {
            Story::for(Delivery::class)->noun('delivery|deliveries');
            Story::for(Delivery::class)->missing('object', 'target');
        });

        expect(Storyfeed::middleware('delivery', 'confirm'))->toBe([Batch::class, Trace::class.':audit']);
    });

    it('runs a message class\'s middleware() method, as a job\'s', function () {
        Story::for(Delivery::class)->verb('sort', DeliveryWasSorted::class);

        Storyfeed::publish(new DeliveryWasSorted(delivery()));

        expect(Storyfeed::middleware('delivery', 'sort'))->toBe([Batch::class, Trace::class.':class', Batch::class.':5 minutes'])
            ->and(Trace::$calls)->toBe(['class:before', 'class:after:stored']);
    });

    it('lets a bound line take middleware and the shortcuts, ahead of the class\'s own', function () {
        Story::middleware('trace:group')->group(function () use (&$bound) {
            $bound = Story::verb('sort', DeliveryWasSorted::class)->middleware('trace:line')->unbatched();
        });

        expect($bound)->toBeInstanceOf(BoundStory::class);
        expect(Storyfeed::middleware('delivery', 'sort'))->toBe([Trace::class.':group', Trace::class.':line', Trace::class.':class']);
    });

    it('gives a resource\'s verbs its middleware', function () {
        Story::resource(Customer::class)->middleware('trace:resource')->withoutMiddleware('batch');

        expect(Storyfeed::middleware('customer', 'create'))->toBe([Trace::class.':resource'])
            ->and(Storyfeed::middleware('customer', 'delete'))->toBe([Trace::class.':resource']);
    });

    it('declares it in the array form an action may return', function () {
        defineStories(Verb::make('delivery.confirm')->fill(['middleware' => ['trace:array'], 'withoutMiddleware' => 'batch'], 'delivery.confirm'));

        expect(Storyfeed::middleware('delivery', 'confirm'))->toBe([Trace::class.':array']);
    });
});

describe('batched() and unbatched()', function () {
    it('replaces the default batch with a windowed one', function () {
        Story::verb('add')->batched(within: '5 minutes');

        expect(Story::verb('comment')->batched(within: '5 minutes')->middleware())->toBe(['batch:5 minutes'])
            ->and(Storyfeed::middleware(null, 'add'))->toBe([Batch::class.':5 minutes']);
    });

    it('makes sure batch is there with no window', function () {
        Story::middlewareGroup('default', []);
        Story::verb('add')->batched();

        expect(Storyfeed::middleware(null, 'add'))->toBe([Batch::class]);
    });

    it('takes batch out, including one a group gave the verb', function () {
        Story::middleware('batch:1 hour')->group(fn () => Story::verb('create')->unbatched());

        expect(Storyfeed::middleware(null, 'create'))->toBe([]);
    });

    it('lets the last of the two win', function () {
        Story::verb('add')->batched('5 minutes')->unbatched();
        Story::verb('create')->unbatched()->batched('1 hour');

        expect(Storyfeed::middleware(null, 'add'))->toBe([])
            ->and(Storyfeed::middleware(null, 'create'))->toBe([Batch::class.':1 hour']);
    });

    it('stores a DateInterval as its ISO 8601 duration', function () {
        expect(Story::verb('add')->batched(new DateInterval('PT5M'))->middleware())->toBe(['batch:PT5M']);
    });

    it('refuses a window that is not a positive interval', function () {
        Story::verb('add')->batched('soon');
    })->throws(InvalidArgumentException::class, "->batched() on [*.add] was given within: 'soon'");
});

describe('precedence: call site, then scope, then middleware, then defaults', function () {
    beforeEach(function () {
        Story::aliasMiddleware('act-as', ActAs::class);
        Story::aliasMiddleware('in-context', InContext::class);
        Story::verb('confirm')->middleware(['act-as:Stripe', 'in-context:Warehouse']);
    });

    it('lets a middleware set the actor and context when nothing above it has', function () {
        $this->actingAs(User::create(['name' => 'Sally', 'email' => 'sally@example.com']));

        $activity = Storyfeed::activity('confirm', delivery())->publish();

        expect($activity->actor->name)->toBe('Stripe')
            ->and($activity->context->name)->toBe('Warehouse');
    });

    it('keeps the call site\'s actor and context', function () {
        $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
        $customer = Customer::create(['name' => 'Acme']);

        $activity = Storyfeed::activity('confirm', delivery())->actor($sally)->context($customer)->publish();

        expect($activity->actor->is($sally))->toBeTrue()
            ->and($activity->context->is($customer))->toBeTrue();
    });

    it('keeps an explicit anonymously(), which is not "nobody said"', function () {
        $activity = Storyfeed::activity('confirm', delivery())->anonymously()->publish();

        expect($activity->actor_type)->toBeNull()
            ->and($activity->actor_id)->toBeNull();
    });

    it('keeps the Storyfeed::as() and Storyfeed::context() scopes', function () {
        $activity = Storyfeed::as('Paddle', fn () => Storyfeed::context('Depot', fn () => Storyfeed::activity('confirm', delivery())->publish()));

        expect($activity->actor->name)->toBe('Paddle')
            ->and($activity->context->name)->toBe('Depot');
    });

    it('ranks above the verb\'s own ->actor(), a default', function () {
        Story::verb('confirm')->actor('Paddle');

        expect(Storyfeed::activity('confirm', delivery())->publish()->actor->name)->toBe('Stripe');
    });
});

describe('the fake', function () {
    it('runs the same pipeline', function () {
        $fake = Storyfeed::fake();
        Story::verb('confirm')->middleware(['trace:fake', function (PendingActivity $activity, Closure $next) {
            return $next($activity->data(['via' => 'middleware']));
        }]);

        Storyfeed::activity('confirm', delivery())->publish();

        $fake->assertPublished(fn (Activity $activity) => $activity->data === ['via' => 'middleware']);
        expect(Trace::$calls)->toBe(['fake:before', 'fake:after:unsaved']);
    });

    it('records nothing a middleware short-circuits', function () {
        $fake = Storyfeed::fake();
        Story::verb('confirm')->middleware(fn () => null);

        Storyfeed::activity('confirm', delivery())->publish();

        $fake->assertNothingPublished();
    });
});

describe('tooling', function () {
    it('shows the resolved middleware in storyfeed:list', function () {
        Story::for(Delivery::class)->verb('add')->batched(within: '5 minutes');
        Story::for(Customer::class)->verb('create')->unbatched();
        Story::verb('comment')->headline(':actor commented');

        Artisan::call('storyfeed:list', ['--json' => true]);
        $rows = collect(json_decode(Artisan::output(), true))->keyBy('verb');

        expect($rows['add']['middleware'])->toBe([Batch::class.':5 minutes'])
            ->and($rows['create']['middleware'])->toBe([])
            ->and($rows['comment']['middleware'])->toBe([Batch::class]);
    });

    it('adds the column to the table with -v, as route:list does', function () {
        Story::verb('comment')->headline(':actor commented');

        Artisan::call('storyfeed:list');
        expect(Artisan::output())->not->toContain(Batch::class);

        try {
            Artisan::call('storyfeed:list', ['-v' => true]);
            expect(Artisan::output())->toContain('| Middleware')->toContain(Batch::class);
        } finally {
            // Symfony keeps -v for the process in SHELL_VERBOSITY.
            putenv('SHELL_VERBOSITY');
            unset($_ENV['SHELL_VERBOSITY'], $_SERVER['SHELL_VERBOSITY']);
        }
    });

    it('survives storyfeed:cache as names, closures included', function () {
        Story::verb('add')->batched(within: '5 minutes')->middleware(static function (PendingActivity $activity, Closure $next) {
            return $next($activity->data(['cached' => true]));
        });

        Artisan::call('storyfeed:cache');
        Storyfeed::useCompiledStories(app(StoryManifest::class)->read());

        $declared = Storyfeed::storyMiddleware()['*.add'];

        expect($declared['middleware'][0])->toBe('batch:5 minutes')
            ->and($declared['middleware'][1])->toBeInstanceOf(Closure::class)
            ->and($declared['excluded'])->toBe(['batch'])
            ->and(Storyfeed::activity('add')->actor('Courier')->publish()->data)->toBe(['cached' => true]);
    });
});
