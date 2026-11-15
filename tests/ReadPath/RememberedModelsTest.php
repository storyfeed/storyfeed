<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Support\ModelHydrator;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * FeedBuilder::remember() — models the host already loaded, offered to
 * hydration (#243). A remembered model stands in for a query only when it
 * already carries everything the resolver asks for; otherwise the query
 * runs as before, so remembering never costs a query and never changes the
 * payload.
 */

beforeEach(function () {
    Customer::$hydrates = false;
    Customer::$hydratesTrashed = false;
    Customer::$hydratesCount = [];
    Customer::$hydrated = [];
    Delivery::$hydrates = false;
    Delivery::$hydratesWith = [];
    Delivery::$readsCustomer = false;
});

afterEach(function () {
    Customer::$hydrates = false;
    Customer::$hydratesTrashed = false;
    Customer::$hydratesCount = [];
    Customer::$hydrated = [];
    Delivery::$hydrates = false;
    Delivery::$hydratesWith = [];
    Delivery::$readsCustomer = false;
});

/**
 * Count the queries a callback issues on the default connection.
 */
function remembered_queries(Closure $callback): int
{
    $connection = DB::connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    try {
        $callback();

        return count($connection->getQueryLog());
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
}

/**
 * A record page: one delivery's own feed. The delivery's resolver hydrates
 * it with its customer, and the customer, shown as the target, hydrates
 * with a count of its deliveries.
 *
 * @return array{delivery: Delivery, customer: Customer}
 */
function record_page(): array
{
    $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test']);
    $customer = Customer::create(['name' => 'Acme']);
    $delivery = Delivery::create(['customer_id' => $customer->id, 'tracking_number' => 'TN-1', 'status' => 'draft']);
    Delivery::create(['customer_id' => $customer->id, 'tracking_number' => 'TN-2', 'status' => 'draft']);

    foreach (['confirm', 'dispatch', 'deliver'] as $verb) {
        Storyfeed::activity($verb, $delivery)->actor($user)->target($customer)->publish();
    }

    Delivery::$hydrates = true;
    Delivery::$hydratesWith = ['customer'];
    Delivery::$readsCustomer = true;
    Customer::$hydrates = true;
    Customer::$hydratesCount = ['deliveries'];

    return ['delivery' => $delivery, 'customer' => $customer];
}

it('runs no hydration queries for a record page that remembers its loaded subject and relations', function () {
    ['delivery' => $delivery] = record_page();

    $unseeded = remembered_queries(fn () => $delivery->storyfeed()->log()->get()->toArray());

    // What the record page already holds: the delivery, its customer, and
    // the customer's delivery count.
    $held = Delivery::query()->with(['customer' => fn ($query) => $query->withCount('deliveries')])->findOrFail($delivery->id);

    $seeded = remembered_queries(fn () => $delivery->storyfeed()->log()->remember([$held, $held->customer])->get()->toArray());

    // Unseeded, hydration reads the delivery, its customer, and the customer
    // with its count again: three queries the page has already paid for.
    expect($seeded)->toBe($unseeded - 3);
});

it('costs nothing extra to remember bare models: the query runs as it would have', function () {
    ['delivery' => $delivery, 'customer' => $customer] = record_page();

    $read = fn (array $remember) => remembered_queries(
        fn () => $delivery->storyfeed()->log()->remember($remember)->get()->toArray(),
    );

    $unseeded = $read([]);

    // Neither carries what its resolver asks for: the delivery has no
    // customer loaded, the customer no count. Used as they are, the delivery
    // would lazy-load its customer behind the batch's back.
    $bare = [Delivery::query()->findOrFail($delivery->id), Customer::query()->findOrFail($customer->id)];

    expect($read($bare))->toBe($unseeded)
        ->and($bare[0]->relationLoaded('customer'))->toBeFalse()
        ->and(array_key_exists('deliveries_count', $bare[1]->getAttributes()))->toBeFalse();
});

it('reuses a remembered model only for the calls it satisfies, and never loads onto it', function () {
    ['delivery' => $delivery] = record_page();

    Customer::$hydrates = false;
    $bare = Delivery::query()->findOrFail($delivery->id);
    $loaded = Delivery::query()->with('customer')->findOrFail($delivery->id);

    $hydrator = new ModelHydrator(true);
    $hydrator->offer($bare);

    expect(remembered_queries(fn () => $hydrator->model('delivery', $delivery->id)))->toBe(0)
        ->and($hydrator->model('delivery', $delivery->id))->toBe($bare);

    $model = $hydrator->model('delivery', $delivery->id, with: ['customer']);

    expect($model)->not->toBe($bare)
        ->and($model?->relationLoaded('customer'))->toBeTrue()
        ->and($bare->relationLoaded('customer'))->toBeFalse();

    $hydrator = new ModelHydrator(true);
    $hydrator->offer($loaded);

    expect(remembered_queries(fn () => $hydrator->model('delivery', $delivery->id, with: ['customer'])))->toBe(0)
        ->and($hydrator->model('delivery', $delivery->id, with: ['customer:id,name']))->toBe($loaded)
        ->and($hydrator->model('delivery', $delivery->id, with: ['customer.deliveries']))->not->toBe($loaded)
        ->and($loaded->customer?->relationLoaded('deliveries'))->toBeFalse();
});

it('follows a nested with: path through every loaded member', function () {
    $customer = Customer::create(['name' => 'Acme']);
    Delivery::create(['customer_id' => $customer->id, 'tracking_number' => 'TN-1', 'status' => 'draft']);
    Delivery::create(['customer_id' => $customer->id, 'tracking_number' => 'TN-2', 'status' => 'draft']);

    $nested = Customer::query()->with('deliveries.customer')->findOrFail($customer->id);
    $shallow = Customer::query()->with('deliveries')->findOrFail($customer->id);

    $hydrator = new ModelHydrator(true);
    $hydrator->offer($nested);

    expect($hydrator->model('customer', $customer->id, with: ['deliveries.customer']))->toBe($nested);

    $hydrator = new ModelHydrator(true);
    $hydrator->offer($shallow);

    expect($hydrator->model('customer', $customer->id, with: ['deliveries.customer']))->not->toBe($shallow);
});

it('passes over a remembered model with unsaved changes, a trashed one, or one missing a count', function () {
    $customer = Customer::create(['name' => 'Acme']);

    $dirty = Customer::query()->findOrFail($customer->id);
    $dirty->name = 'Renamed, not saved';

    $hydrator = new ModelHydrator(true);
    $hydrator->offer($dirty);

    expect($hydrator->model('customer', $customer->id)?->name)->toBe('Acme');

    $counted = Customer::query()->withCount('deliveries')->findOrFail($customer->id);
    $aliased = Customer::query()->withCount('deliveries as shipped')->findOrFail($customer->id);

    $hydrator = new ModelHydrator(true);
    $hydrator->offer($counted);

    expect($hydrator->model('customer', $customer->id, withCount: ['deliveries']))->toBe($counted)
        ->and($hydrator->model('customer', $customer->id, withCount: ['deliveries as shipped']))->not->toBe($counted);

    $hydrator = new ModelHydrator(true);
    $hydrator->offer($aliased);

    expect($hydrator->model('customer', $customer->id, withCount: ['deliveries as shipped']))->toBe($aliased);

    $customer->delete();
    $trashed = Customer::withTrashed()->findOrFail($customer->id);

    $hydrator = new ModelHydrator(true);
    $hydrator->offer($trashed);

    expect($hydrator->model('customer', $customer->id))->toBeNull()
        ->and($hydrator->model('customer', $customer->id, withTrashed: true))->toBe($trashed);
});

it('ignores remembered models when hydration is switched off, and unsaved ones always', function () {
    $customer = Customer::create(['name' => 'Acme']);

    $hydrator = new ModelHydrator(false);
    $hydrator->offer($customer);

    expect($hydrator->model('customer', $customer->id))->toBeNull();

    $unsaved = new Customer(['name' => 'Draft']);
    $unsaved->id = $customer->id;

    $hydrator = new ModelHydrator(true);
    $hydrator->offer($unsaved);

    expect($hydrator->model('customer', $customer->id))->not->toBe($unsaved)
        ->and($hydrator->model('customer', $customer->id)?->name)->toBe('Acme');
});

it('builds the same payload with and without remembered models', function () {
    ['delivery' => $delivery] = record_page();

    $held = Delivery::query()->with(['customer' => fn ($query) => $query->withCount('deliveries')])->findOrFail($delivery->id);

    $unseeded = json_encode($delivery->storyfeed()->cursorPaginate(10));
    $seeded = json_encode($delivery->storyfeed()->remember(collect([$held, $held->customer]))->cursorPaginate(10));

    expect($seeded)->toBe($unseeded)
        ->and(json_encode($delivery->storyfeed()->log()->remember($held)->get()))
        ->toBe(json_encode($delivery->storyfeed()->log()->get()));
});

it('keeps remembered models to their read, even through a singleton-bound presenter', function () {
    app()->singleton(NodePresenter::class);

    ['delivery' => $delivery] = record_page();

    $unseeded = remembered_queries(fn () => $delivery->storyfeed()->log()->get()->toArray());

    $held = Delivery::query()->with(['customer' => fn ($query) => $query->withCount('deliveries')])->findOrFail($delivery->id);
    $delivery->storyfeed()->log()->remember([$held, $held->customer])->get()->toArray();

    // Behind the held model's back: only a fresh load sees the new status.
    DB::table('deliveries')->where('id', $delivery->id)->update(['status' => 'delivered']);

    $next = null;
    $queries = remembered_queries(function () use ($delivery, &$next) {
        $next = $delivery->storyfeed()->log()->get()->toArray();
    });

    expect($queries)->toBe($unseeded)
        ->and($next[0]['object']['link']['attributes']['data-status'])->toBe('delivered');
});

it('takes models only', function () {
    Storyfeed::feed()->remember([new Customer, 'customer:1']);
})->throws(InvalidArgumentException::class, 'remember() takes Eloquent models, got string.');
