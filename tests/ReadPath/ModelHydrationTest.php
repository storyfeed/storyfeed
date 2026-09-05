<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\Models\Activity;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Support\ModelHydrator;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * $context->model() — lazy, batched per class across the page (issue #4).
 *
 * The presenter seeds an identity map with every (type, id) the page holds
 * before any resolver runs; the first entity of a class to ask loads the
 * whole class in one query, every later one is a map hit. Nothing here is
 * used by the workbench models unless a test switches it on, so the rest of
 * the suite measures the zero-cost path.
 */

beforeEach(function () {
    Customer::$hydrates = false;
    Customer::$hydratesTrashed = false;
    Customer::$hydrated = [];
    Delivery::$hydrates = false;
    Delivery::$hydratesWith = [];
    Delivery::$readsCustomer = false;
});

afterEach(function () {
    Customer::$hydrates = false;
    Customer::$hydratesTrashed = false;
    Customer::$hydrated = [];
    Delivery::$hydrates = false;
    Delivery::$hydratesWith = [];
    Delivery::$readsCustomer = false;
});

/**
 * Count the queries a callback issues on the default connection.
 */
function queries_during(Closure $callback): int
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
 * The representative page: 3 users onboarding 8 customers and confirming 12
 * deliveries — 20 activities, three Feedable classes, customers appearing as
 * both objects and targets.
 *
 * @return array{users: list<User>, customers: list<Customer>, deliveries: list<Delivery>}
 */
function representative_page(): array
{
    $users = collect(range(1, 3))->map(fn (int $i) => User::create(['name' => "User {$i}", 'email' => "u{$i}@example.test"]))->all();
    $customers = collect(range(1, 8))->map(fn (int $i) => Customer::create(['name' => "Customer {$i}"]))->all();
    $deliveries = collect(range(1, 12))->map(fn (int $i) => Delivery::create([
        'customer_id' => $customers[$i % 8]->id,
        'tracking_number' => "TN-{$i}",
        'status' => 'draft',
    ]))->all();

    foreach ($customers as $i => $customer) {
        Storyfeed::activity('onboard', $customer)->actor($users[$i % 3])->publish();
    }

    foreach ($deliveries as $i => $delivery) {
        Storyfeed::activity('confirm', $delivery)->actor($users[$i % 3])->target($customers[$i % 8])->publish();
    }

    return ['users' => $users, 'customers' => $customers, 'deliveries' => $deliveries];
}

it('returns the live model to a resolver, batched: one query per class however many entities ask', function () {
    representative_page();

    $page = fn () => Storyfeed::feed()->log()->limit(20)->get()->toArray();

    $baseline = queries_during($page);

    Customer::$hydrates = true;
    $customersOnly = queries_during($page);

    Customer::$hydrated = [];
    Delivery::$hydrates = true;
    $both = queries_during($page);

    // 8 customer objects + 12 customer targets ask; one query answers them
    // all. 12 deliveries ask; one more query.
    expect($customersOnly)->toBe($baseline + 1)
        ->and($both)->toBe($baseline + 2)
        ->and(Customer::$hydrated)->toHaveCount(20)
        ->and(Customer::$hydrated)->each->toBeInstanceOf(Customer::class);

    $items = $page();

    expect($items['items'][0]['object']['url'])->toBe('/deliveries/'.$items['items'][0]['object']['id'])
        ->and($items['items'][0]['target']['url'])->toBe('/customers/'.$items['items'][0]['target']['id']);
});

it('serves one instance per entity for the whole build — the identity map, not a fresh row each time', function () {
    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();
    Storyfeed::activity('onboard', $customer)->publish();

    Customer::$hydrates = true;

    Storyfeed::feed()->log()->get()->toArray();

    expect(Customer::$hydrated)->toHaveCount(2)
        ->and(Customer::$hydrated[0])->toBe(Customer::$hydrated[1]);
});

it('loads relations named in with: on the same batch, so nested access costs nothing more', function () {
    representative_page();

    $page = fn () => Storyfeed::feed()->log()->limit(20)->get()->toArray();

    $baseline = queries_during($page);

    Delivery::$hydrates = true;
    Delivery::$readsCustomer = true;

    // The footgun, measured: reading $model->customer off each hydrated
    // delivery with no with: is an N+1 inside the batch.
    $nested = queries_during($page);

    Delivery::$hydratesWith = ['customer'];
    $eager = queries_during($page);

    expect($nested)->toBe($baseline + 1 + 12)
        ->and($eager)->toBe($baseline + 2);

    $item = collect($page()['items'])->firstWhere('verb', 'confirm');

    expect($item['object']['attributes']['data-customer'])->toStartWith('Customer ');
});

it('answers null for a row that is gone, and the activity still renders with its snapshot label', function () {
    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();

    // Straight through the query builder: no model events, no cascade — the
    // row is gone from under a snapshot that still exists.
    DB::table('customers')->where('id', $customer->id)->delete();

    Customer::$hydrates = true;

    $item = Storyfeed::feed()->log()->get()->toArray()['items'][0];

    expect(Customer::$hydrated)->toBe([null])
        ->and($item['object']['label'])->toBe('Acme')
        ->and($item['object']['url'])->toBeNull();
});

it('hides a soft-deleted row by default and hands it over with withTrashed: true', function () {
    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();

    DB::table('customers')->where('id', $customer->id)->update(['deleted_at' => now()]);

    Customer::$hydrates = true;

    $item = Storyfeed::feed()->log()->get()->toArray()['items'][0];

    expect(Customer::$hydrated)->toBe([null])
        ->and($item['object']['url'])->toBeNull();

    Customer::$hydrated = [];
    Customer::$hydratesTrashed = true;

    $item = Storyfeed::feed()->log()->get()->toArray()['items'][0];

    expect(Customer::$hydrated[0])->toBeInstanceOf(Customer::class)
        ->and(Customer::$hydrated[0]->trashed())->toBeTrue()
        ->and($item['object']['url'])->toBe("/customers/{$customer->id}");
});

it('ignores withTrashed on a class that does not soft-delete instead of throwing', function () {
    $user = User::create(['name' => 'Ada', 'email' => 'ada@example.test']);

    $model = (new ModelHydrator)->model('user', $user->id, withTrashed: true);

    expect($model)->toBeInstanceOf(User::class)
        ->and($model->is($user))->toBeTrue();
});

it('returns null for every way it cannot resolve, and never throws', function () {
    $hydrator = new ModelHydrator;

    expect($hydrator->model('customer', null))->toBeNull()
        ->and($hydrator->model('nothing-registered', 1))->toBeNull()
        ->and($hydrator->model('customer', 999_999))->toBeNull()
        // An alias that resolves to a class, but not to a model.
        ->and($hydrator->model(Closure::class, 1))->toBeNull();
});

it('reports a batch that throws once and answers null for every id it covered', function () {
    Exceptions::fake();

    $broken = new class extends Model implements Feedable
    {
        use InteractsWithFeed;

        protected $table = 'no_such_table';

        public function toFeed(): FeedEntity
        {
            return FeedEntity::make(label: 'Broken');
        }
    };

    Relation::morphMap(['broken' => $broken::class]);

    $hydrator = new ModelHydrator;
    $hydrator->seed('broken', 1);
    $hydrator->seed('broken', 2);

    expect($hydrator->model('broken', 1))->toBeNull()
        ->and($hydrator->model('broken', 2))->toBeNull();

    Exceptions::assertReportedCount(1);
});

it('does nothing when hydration is switched off: no query, no exception, null', function () {
    config(['storyfeed.hydration.enabled' => false]);

    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();

    $page = fn () => Storyfeed::feed()->log()->get()->toArray();

    $baseline = queries_during($page);

    Customer::$hydrates = true;

    expect(queries_during($page))->toBe($baseline)
        ->and(Customer::$hydrated)->toBe([null]);

    $item = $page()['items'][0];

    expect($item['object']['label'])->toBe('Acme')
        ->and($item['object']['url'])->toBeNull();
});

it('starts a fresh identity map for every build, even through a singleton-bound presenter', function () {
    app()->singleton(NodePresenter::class);

    $customer = Customer::create(['name' => 'Before']);

    Storyfeed::activity('onboard', $customer)->publish();

    Customer::$hydrates = true;

    $first = Storyfeed::feed()->log()->get()->toArray()['items'][0];

    // Behind the map's back: no model events, so the snapshot keeps 'Before'
    // and only a fresh load can see 'After'.
    DB::table('customers')->where('id', $customer->id)->update(['name' => 'After']);

    $second = Storyfeed::feed()->log()->get()->toArray()['items'][0];

    expect($first['object']['label'])->toBe('Before')
        ->and($second['object']['label'])->toBe('After')
        ->and(Customer::$hydrated[0])->not->toBe(Customer::$hydrated[1]);
});

it('is a single lookup, not a batch, on the AS2 path — and still correct', function () {
    ['customers' => $customers] = representative_page();

    Customer::$hydrates = true;

    $model = Activity::query()->where('verb', 'onboard')->where('object_id', $customers[0]->id)->firstOrFail();

    $baseline = queries_during(fn () => serialize_one($model));

    Customer::$hydrates = false;
    $without = queries_during(fn () => serialize_one($model));
    Customer::$hydrates = true;

    $document = serialize_one($model);

    // One entity of the class on the document, one query for it.
    expect($baseline)->toBe($without + 1)
        ->and($document['object']['url'])->toBe(url("/customers/{$customers[0]->id}"));
});

it('works on a context built by hand, as a single lookup', function () {
    $customer = Customer::create(['name' => 'Acme']);

    $context = new FeedContext(type: 'customer', id: $customer->id, data: ['id' => $customer->id]);

    expect($context->model())->toBeInstanceOf(Customer::class)
        ->and($context->model()->is($customer))->toBeTrue()
        ->and($context->model())->toBe($context->model());
});

it('lets a hydrating resolver override the stale snapshot label from the live row', function () {
    $customer = Customer::create(['name' => 'Old Name']);

    Storyfeed::activity('onboard', $customer)->publish();

    DB::table('customers')->where('id', $customer->id)->update(['name' => 'New Name']);

    $stale = Storyfeed::feed()->log()->get()->toArray()['items'][0];

    Customer::$hydrates = true;

    $fresh = Storyfeed::feed()->log()->get()->toArray()['items'][0];

    // The known consequence, and its remedy: without the model the label is
    // the snapshot's; a resolver that has paid for the model can return the
    // live label and close the gap.
    expect($stale['object']['label'])->toBe('Old Name')
        ->and($fresh['object']['label'])->toBe('New Name')
        ->and($fresh['object']['data']['name'])->toBe('Old Name');
});

it('names the aliases whose resolver asked for a model — the seam for the doctor (#5)', function () {
    $hydrator = new ModelHydrator;

    expect($hydrator->requested())->toBe([]);

    $hydrator->model('customer', 1);
    $hydrator->model('customer', 2);
    $hydrator->model('delivery', null);

    expect($hydrator->requested())->toBe(['customer', 'delivery']);
});

it('costs a resolver that never asks nothing: the map is seeded, never queried', function () {
    representative_page();

    $page = fn () => Storyfeed::feed()->log()->limit(20)->get()->toArray();

    $baseline = queries_during($page);

    // The whole suite is this case; the assertion pins it beside the
    // hydrating numbers so the two are read together.
    expect(queries_during($page))->toBe($baseline)
        ->and(Customer::$hydrated)->toBe([]);
});
