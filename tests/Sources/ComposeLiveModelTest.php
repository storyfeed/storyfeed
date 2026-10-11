<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Sources\Entry;
use Storyfeed\Tests\Fixtures\Models\Poster;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

/*
 * A composed entry names its roles with the model itself, so
 * $context->model() hands that instance back: no query for a saved model,
 * and an unsaved one is not lost (#138).
 */

beforeEach(function () {
    Relation::morphMap(['poster' => Poster::class]);
    Customer::$hydrates = false;
    Customer::$hydratesCount = [];
    Customer::$hydrated = [];
    Delivery::$hydrates = false;
    Delivery::$hydratesWith = [];
    Delivery::$readsCustomer = false;
});

afterEach(function () {
    Customer::$hydrates = false;
    Customer::$hydratesCount = [];
    Customer::$hydrated = [];
    Delivery::$hydrates = false;
    Delivery::$hydratesWith = [];
    Delivery::$readsCustomer = false;
});

/** The queries a callback issues on the default connection. */
function live_model_queries(Closure $callback): int
{
    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });

    $callback();

    return $count;
}

it('hands an unsaved model to its resolver, so media read from the model arrives', function () {
    Exceptions::fake();

    $poster = new Poster(['title' => 'Spring', 'slug' => 'spring', 'cover' => 'https://example.test/spring.png']);

    $item = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->by('Tey Labs')->verb('publish', $poster))
        ->get()->toArray()[0];

    expect($item['object']['label'])->toBe('Spring')
        ->and($item['object']['link']['href'])->toBe('/posters/spring')
        ->and($item['object']['media']['image']['src'])->toBe('https://example.test/spring.png');

    Exceptions::assertNothingReported();
});

it('runs no query for a saved model a resolver asks for on compose', function () {
    $customers = collect(range(1, 3))->map(fn (int $i) => Customer::create(['name' => "Customer {$i}"]));

    $page = fn () => Storyfeed::compose()->inOrder()
        ->addMany($customers, fn (Customer $customer, Entry $entry) => $entry->verb('onboard', $customer))
        ->get()->toArray();

    $baseline = live_model_queries($page);

    Customer::$hydrates = true;

    expect(live_model_queries($page))->toBe($baseline)
        ->and($baseline)->toBe(0)
        ->and(Customer::$hydrated)->toHaveCount(3)
        ->and(Customer::$hydrated[0])->toBe($customers[0])
        ->and($page()[0]['object']['link']['href'])->toBe('/customers/'.$customers[0]->id);
});

it('loads with: relations onto the instances, batched across the page', function () {
    $customer = Customer::create(['name' => 'Acme']);
    $deliveries = collect(range(1, 3))->map(fn () => Delivery::create(['customer_id' => $customer->id, 'status' => 'confirmed']))
        ->map(fn (Delivery $delivery) => $delivery->withoutRelations());

    Delivery::$hydrates = true;
    Delivery::$readsCustomer = true;
    Delivery::$hydratesWith = ['customer'];

    $items = [];
    $queries = live_model_queries(function () use ($deliveries, &$items) {
        $items = Storyfeed::compose()->inOrder()
            ->addMany($deliveries, fn (Delivery $delivery, Entry $entry) => $entry->verb('confirm', $delivery))
            ->get()->toArray();
    });

    expect($queries)->toBe(1)
        ->and($deliveries)->each(fn ($delivery) => $delivery->relationLoaded('customer')->toBeTrue())
        ->and($items[0]['object']['link']['attributes']['data-customer'])->toBe('Acme');
});

it('counts withCount: onto the instances in one query', function () {
    $customers = collect(range(1, 3))->map(fn (int $i) => Customer::create(['name' => "Customer {$i}"]));
    Delivery::create(['customer_id' => $customers[0]->id, 'status' => 'confirmed']);
    Delivery::create(['customer_id' => $customers[0]->id, 'status' => 'confirmed']);

    Customer::$hydrates = true;
    Customer::$hydratesCount = ['deliveries'];

    $queries = live_model_queries(fn () => Storyfeed::compose()->inOrder()
        ->addMany($customers, fn (Customer $customer, Entry $entry) => $entry->verb('onboard', $customer))
        ->get()->toArray());

    expect($queries)->toBe(1)
        ->and($customers->pluck('deliveries_count')->all())->toBe([2, 0, 0]);
});

it('leaves the stored feed hydrating from the database', function () {
    $customer = Customer::create(['name' => 'Acme']);
    Storyfeed::activity('onboard', $customer)->publish();

    Customer::$hydrates = true;

    $queries = live_model_queries(fn () => Storyfeed::feed()->log()->get()->toArray());

    Customer::$hydrates = false;
    $baseline = live_model_queries(fn () => Storyfeed::feed()->log()->get()->toArray());

    expect($queries)->toBe($baseline + 1)
        ->and(Customer::$hydrated)->toHaveCount(1)
        ->and(Customer::$hydrated[0])->not->toBe($customer)
        ->and(Customer::$hydrated[0]->is($customer))->toBeTrue();
});
