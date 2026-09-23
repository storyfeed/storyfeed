<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Storyfeed\Actions\TrickleSnapshots;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\Models\Snapshot;
use Workbench\App\Models\Customer;

/*
 * The route key rides the snapshot's `meta`, written with the label, so a
 * resolver can link a slug- or UUID-routed model with no query and nothing
 * added to `data`.
 */

beforeEach(function () {
    Customer::$lastContext = null;
});

function slugRouted(): Customer
{
    $model = new class extends Customer
    {
        protected $table = 'customers';

        public function getRouteKeyName(): string
        {
            return 'name';
        }
    };

    Relation::morphMap(['slugged' => $model::class]);

    return $model::create(['name' => 'acme-bistro']);
}

it('records a route key that is not the primary key and hands it to the resolver', function () {
    $customer = slugRouted();

    Storyfeed::activity('onboard', $customer)->publish();
    Storyfeed::feed()->get()->toArray();

    $snapshot = Snapshot::query()->where('model_type', 'slugged')->sole();
    $context = Customer::$lastContext;

    expect($snapshot->meta)->toBe(['route_key' => 'acme-bistro'])
        ->and($context->key())->toBe($customer->id)
        ->and($context->routeKey())->toBe('acme-bistro')
        ->and($context->data())->not->toHaveKey('route_key');
});

it('stores nothing extra when the route key is the primary key, and routeKey() is key()', function () {
    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();
    Storyfeed::feed()->get()->toArray();

    $snapshot = Snapshot::query()->where('model_type', 'customer')->sole();

    expect($snapshot->meta)->toBe([])
        ->and(Customer::$lastContext->routeKey())->toBe($customer->id);
});

it('falls back to key() for a snapshot written before the route key was recorded', function () {
    $customer = slugRouted();

    Storyfeed::activity('onboard', $customer)->publish();
    Snapshot::query()->where('model_type', 'slugged')->update(['meta' => null]);
    Storyfeed::feed()->get()->toArray();

    expect(Customer::$lastContext->routeKey())->toBe($customer->id)
        ->and((new FeedContext(type: 'slugged', key: 7))->routeKey())->toBe(7);
});

it('lets the trickle record the route key on a row written before it existed', function () {
    slugRouted();
    Storyfeed::activity('onboard', Customer::query()->sole())->publish();

    $snapshot = Snapshot::query()->where('model_type', 'slugged')->sole();
    $snapshot->forceFill(['meta' => null])->saveQuietly();

    expect((new TrickleSnapshots)())->toMatchArray(['reshaped' => 1])
        ->and($snapshot->fresh()->meta)->toBe(['route_key' => 'acme-bistro'])
        ->and((new TrickleSnapshots)())->toMatchArray(['reshaped' => 0]);
});
