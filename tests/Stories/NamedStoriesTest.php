<?php

use Illuminate\Support\Facades\Artisan;
use Storyfeed\Exceptions\StoryMisconfigured;
use Storyfeed\Exceptions\StoryNotFound;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Party;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Stories\Verb;
use Storyfeed\StoryfeedManager;
use Storyfeed\Tests\Fixtures\Stories\DeliveryStory;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

/*
 * Named stories, modelled on named routes. A name is optional and explicit
 * (`->name()`), a resource names its verbs, `Story::name()->group()`
 * prefixes names, and `story()` / `Storyfeed::route()` reference them. The
 * name lives in code only: a row keeps its type and verb, and its name is
 * looked up from that key when read.
 */

afterEach(function () {
    app(StoryManifest::class)->delete();
});

// ── Defining ────────────────────────────────────────────────────────────

it('names a verb with ->name(), and leaves a verb without one unnamed', function () {
    Story::for(Delivery::class)->verb('confirm')->name('checkout.confirm');
    Story::for(Delivery::class)->verb('ship');

    expect(Storyfeed::storyNames())->toBe(['checkout.confirm' => 'delivery.confirm'])
        ->and(Story::has('checkout.confirm'))->toBeTrue()
        ->and(Story::has('delivery.ship'))->toBeFalse();
});

it('appends to a name already given, as Route::name() does', function () {
    Story::for(Delivery::class)->verb('confirm')->name('checkout.')->name('confirm');

    expect(Story::has('checkout.confirm'))->toBeTrue();
});

it('prefixes names inside Story::name()->group(), nested outermost first', function () {
    Story::name('billing.')->group(function () {
        Story::for(Customer::class)->verb('invoice')->name('customer.invoiced');

        Story::name('ops.')->group(function () {
            Story::for(Delivery::class)->verb('ship')->name('delivery.ship');
        });

        // Unnamed in a group stays unnamed: only a name is prefixed.
        Story::for(Delivery::class)->verb('hold');
    });

    Story::for(Delivery::class)->verb('confirm')->name('delivery.confirm');

    expect(Storyfeed::storyNames())->toBe([
        'billing.customer.invoiced' => 'customer.invoice',
        'billing.ops.delivery.ship' => 'delivery.ship',
        'delivery.confirm' => 'delivery.confirm',
    ]);
});

it('nests and chains canonical as prefixes and name aliases in either order', function (string $outer, string $inner) {
    Story::{$outer}('billing.')->{$inner}('admin.')->group(function () use ($inner, $outer) {
        Story::{$inner}('ops.')->{$outer}('dispatch.')->group(function () {
            Story::for(Delivery::class)->verb('ship')->name('delivery.ship');
        });
    });

    Story::for(Delivery::class)->verb('confirm')->name('delivery.confirm');

    expect(Storyfeed::storyNames())->toBe([
        'billing.admin.ops.dispatch.delivery.ship' => 'delivery.ship',
        'delivery.confirm' => 'delivery.confirm',
    ]);
})->with([['as', 'name'], ['name', 'as'], ['as', 'as'], ['name', 'name']]);

it('names a bound class on its line, inside a type scope too', function () {
    Story::for(Delivery::class)->verb('ship', ShipNamed::class)->name('delivery.ship');
    Story::name('any.')->group(fn () => Story::verb('wave', ShipNamed::class)->name('wave'));

    expect(Storyfeed::storyNames())->toBe(['delivery.ship' => 'delivery.ship', 'any.wave' => '*.wave']);
});

it('refuses a name for more than one type, since a name names one key', function () {
    Story::for([Delivery::class, Customer::class])->verb('ship')->name('ship');
})->throws(StoryMisconfigured::class, "->name('ship') at ");

it('refuses a name for a fallback, which has no verb to record', function () {
    Story::for(Delivery::class)->fallback()->name('delivery');
})->throws(StoryMisconfigured::class, 'names the fallback [delivery.*]');

// ── Resources name their verbs ──────────────────────────────────────────

it('names every resource verb {type}.{verb}, singular, identical to the key', function () {
    Story::resource(Delivery::class, DeliveryStory::class)->only(['create', 'update', 'confirm_payment', 'ship']);

    expect(Storyfeed::storyNames())->toBe([
        'delivery.create' => 'delivery.create',
        'delivery.update' => 'delivery.update',
        'delivery.confirm_payment' => 'delivery.confirm_payment',
        'delivery.ship' => 'delivery.ship',
    ]);
});

it('takes names() as a prefix or verb by verb, and name() for one, as Route::resource() does', function () {
    Story::resource(Delivery::class)->only('create', 'update')->names('deliveries');
    Story::resource(Customer::class)->only('create', 'update')->names(['update' => 'customer.edit']);
    Story::resource('courier')->only('create')->name('create', 'courier.hired');

    expect(Storyfeed::storyNames())->toBe([
        'deliveries.create' => 'delivery.create',
        'deliveries.update' => 'delivery.update',
        'customer.create' => 'customer.create',
        'customer.edit' => 'customer.update',
        'courier.hired' => 'courier.create',
    ]);
});

it('names a resource over several types for each one, under a group prefix', function () {
    Story::name('crm.')->group(fn () => Story::resource(['delivery', 'customer'])->only('create'));

    expect(Storyfeed::storyNames())->toBe([
        'crm.delivery.create' => 'delivery.create',
        'crm.customer.create' => 'customer.create',
    ]);
});

it('names a package-owned type by its package alias', function () {
    Story::resource(Party::class)->only('create');

    expect(Storyfeed::storyNames())->toBe(['storyfeed.party.create' => 'storyfeed.party.create']);
});

it('keeps a name a resource action gave its own verb', function () {
    Story::resource(Delivery::class, NamingDeliveryStory::class)->only('ship');

    expect(Storyfeed::storyNames())->toBe(['delivery.dispatched' => 'delivery.ship']);
});

// ── Referencing ─────────────────────────────────────────────────────────

it('publishes a resource verb by its name', function () {
    Story::resource(Delivery::class);

    $activity = story('delivery.create', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    expect($activity->verb)->toBe('create')->and($activity->object_type)->toBe('delivery');
});

it('lets the last of two stories with one name win at runtime, as routes do', function () {
    Story::for(Delivery::class)->verb('ship')->name('dispatch');
    Story::for(Customer::class)->verb('onboard')->name('dispatch');

    expect(Storyfeed::namedStory('dispatch'))->toBe('customer.onboard')
        ->and(story('dispatch', Customer::create(['name' => 'Acme']))->publish()->verb)->toBe('onboard');
});

// ── Inspecting ──────────────────────────────────────────────────────────

it('checks every name given, as Route::has() does', function () {
    Story::resource(Delivery::class)->only('create', 'update');

    expect(Story::has(['delivery.create', 'delivery.update']))->toBeTrue()
        ->and(Story::has(['delivery.create', 'delivery.delete']))->toBeFalse()
        ->and(Storyfeed::hasNamedStory('delivery.create'))->toBeTrue();
});

it('reads a row\'s name from its key, never storing it', function () {
    Story::resource(Delivery::class)->only('create');
    Story::verb('wave')->name('greeting.wave');

    $created = Storyfeed::activity('create', Delivery::create(['tracking_number' => 'TN-1']))->publish();
    $waved = Storyfeed::activity('wave', Customer::create(['name' => 'Acme']))->publish();
    $unnamed = Storyfeed::activity('ping')->publish();

    expect($created->storyName())->toBe('delivery.create')
        ->and($waved->storyName())->toBe('greeting.wave')
        ->and($unnamed->storyName())->toBeNull()
        ->and(array_keys($created->fresh()->getAttributes()))->not->toContain('story');
});

it('matches names with wildcards in storyIs(), as routeIs() does', function () {
    Story::resource(Delivery::class)->only('create');

    $activity = Storyfeed::activity('create', Delivery::create(['tracking_number' => 'TN-1']))->publish();
    $unnamed = Storyfeed::activity('ping')->publish();

    expect($activity->storyIs('delivery.*'))->toBeTrue()
        ->and($activity->storyIs('customer.*', 'delivery.create'))->toBeTrue()
        ->and($activity->storyIs('customer.*'))->toBeFalse()
        ->and($unnamed->storyIs('*'))->toBeFalse();
});

it('follows a rename with nothing to migrate', function () {
    Story::for(Delivery::class)->verb('ship')->name('delivery.ship');
    $activity = Storyfeed::activity('ship', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    app()->forgetInstance(StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();
    Story::for(Delivery::class)->verb('ship')->name('parcel.sent');

    expect($activity->storyName())->toBe('parcel.sent');
});

it('shows the name in storyfeed:list, its JSON, and filters by --name as route:list does', function () {
    Story::resource(Delivery::class)->only('create');
    Story::for(Delivery::class)->verb('ship');

    Artisan::call('storyfeed:list', ['--json' => true]);
    $rows = collect(json_decode(Artisan::output(), true));

    Artisan::call('storyfeed:list', ['--name' => 'delivery.cr', '--json' => true]);
    $filtered = collect(json_decode(Artisan::output(), true));

    Artisan::call('storyfeed:list');

    expect($rows->firstWhere('verb', 'create')['name'])->toBe('delivery.create')
        ->and($rows->firstWhere('verb', 'ship')['name'])->toBeNull()
        ->and($filtered->pluck('verb')->all())->toBe(['create'])
        ->and(Artisan::output())->toContain('Name')->toContain('delivery.create');
});

// ── storyfeed:cache ─────────────────────────────────────────────────────

it('refuses two stories with one name in storyfeed:cache, as route:cache does', function () {
    Story::for(Delivery::class)->verb('ship')->name('dispatch');
    Story::for(Customer::class)->verb('onboard')->name('dispatch');

    $status = Artisan::call('storyfeed:cache');

    expect($status)->toBe(1)
        ->and(Artisan::output())->toContain(
            'Unable to prepare story [customer.onboard] ('.__FILE__.':',
            'for caching. Another story has already been assigned name [dispatch]: [delivery.ship] ('.__FILE__.':',
        );

    expect(app(StoryManifest::class)->exists())->toBeFalse();
});

it('refuses one key with two names in storyfeed:cache', function () {
    Story::for(Delivery::class)->verb('ship')->name('delivery.ship');
    Story::for(Delivery::class)->verb('ship')->name('parcel.sent');

    expect(Artisan::call('storyfeed:cache'))->toBe(1)
        ->and(Artisan::output())->toContain('Unable to prepare story [delivery.ship]', 'It is named [parcel.sent], and already named [delivery.ship]');
});

it('caches one name given twice to one key', function () {
    Story::for(Delivery::class)->verb('ship')->headline(':actor shipped :object')->name('delivery.ship');
    Story::for(Delivery::class)->verb('ship')->icon('send')->name('delivery.ship');

    $this->artisan('storyfeed:cache')->assertSuccessful();
});

it('round-trips names through the manifest', function () {
    Story::resource(Delivery::class)->only('create');
    Story::for(Customer::class)->verb('onboard')->name('customer.welcomed');

    $this->artisan('storyfeed:cache')->assertSuccessful();

    expect(Storyfeed::doctor(['manifest'])->findings)->toBe([]);

    app()->forgetInstance(StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();
    Storyfeed::useCompiledStories(app(StoryManifest::class)->read());

    expect(Storyfeed::storyNames())->toBe(['delivery.create' => 'delivery.create', 'customer.welcomed' => 'customer.onboard'])
        ->and(story('customer.welcomed', Customer::create(['name' => 'Acme']))->publish()->verb)->toBe('onboard');
});

it('throws for an unknown name with a cached manifest too', function () {
    Story::resource(Delivery::class)->only('create');
    $this->artisan('storyfeed:cache')->assertSuccessful();

    app()->forgetInstance(StoryfeedManager::class);
    Storyfeed::clearResolvedInstances();
    Storyfeed::useCompiledStories(app(StoryManifest::class)->read());

    story('delivery.update');
})->throws(StoryNotFound::class, 'Story [delivery.update] not defined.');

class ShipNamed
{
    public function __invoke(Verb $verb): string
    {
        return ':actor shipped :object';
    }
}

class NamingDeliveryStory
{
    public function ship(Verb $verb): Verb
    {
        return $verb->headline(':actor shipped :object')->name('delivery.dispatched');
    }
}
