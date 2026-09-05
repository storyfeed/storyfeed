<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedMedia;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The `hydration` check (issue #5): every Feedable whose resolver asks for
 * its model, named as Info. The edge that matters most is the first test —
 * a check that speaks on an app where nobody hydrates is one that gets
 * switched off, and then the bill is invisible again.
 */

/** A Feedable whose resolver throws on its own snapshot — opaque to the probe. */
class ThrowingResolver extends Model implements Feedable
{
    protected $table = 'customers';

    protected $guarded = [];

    public function toFeed(): FeedEntity
    {
        return FeedEntity::make(label: 'throws');
    }

    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        throw new RuntimeException('a resolver that cannot answer');
    }
}

beforeEach(function () {
    Customer::$hydrates = false;
    Customer::$hydrated = [];
    Delivery::$hydrates = false;
    Delivery::$hydratesWith = [];
});

afterEach(function () {
    Customer::$hydrates = false;
    Customer::$hydrated = [];
    Delivery::$hydrates = false;
    Delivery::$hydratesWith = [];
});

function publishOrder(): void
{
    $user = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $customer = Customer::create(['name' => 'Concur']);

    Storyfeed::activity()
        ->actor($user)
        ->verb('confirm', Delivery::create(['tracking_number' => 'TN-1', 'customer_id' => $customer->id]))
        ->for($customer)
        ->publish();
}

it('says nothing on an app where no resolver hydrates', function () {
    publishOrder();

    $report = Storyfeed::doctor(['hydration']);

    expect($report->all())->toBeEmpty()
        ->and($report->isHealthy())->toBeTrue();
});

it('says nothing before any snapshot exists, even for a resolver that reads its data strictly', function () {
    // The workbench Delivery reads `$data['id']` bare, and nothing has
    // published, so the probe has only an empty snapshot to offer it.
    $report = Storyfeed::doctor(['hydration']);

    expect($report->all())->toBeEmpty();
});

it('names the class that hydrates, as reportage rather than a finding', function () {
    publishOrder();
    Customer::$hydrates = true;

    $report = Storyfeed::doctor(['hydration']);

    $finding = $report->withCode('hydration.model')->sole();

    expect($finding->severity)->toBe(Severity::Info)
        ->and($finding->subject['model'])->toBe(Customer::class)
        ->and($finding->subject['alias'])->toBe('customer')
        ->and($finding->subject['enabled'])->toBeTrue()
        ->and($finding->message)->toContain('under every feed')
        ->and($report->isHealthy())->toBeTrue()
        ->and($report->count())->toBe(0);

    // Delivery did not ask, so it is not named.
    expect($report->all()->pluck('subject.model')->all())->not->toContain(Delivery::class);
});

it('probes without hydrating anything', function () {
    publishOrder();
    Customer::$hydrates = true;

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    Storyfeed::doctor(['hydration']);

    $customerQueries = collect(DB::connection()->getQueryLog())
        ->filter(fn (array $query) => str_contains($query['query'], 'from "customers"'))
        ->count();

    // The resolver was called — the spy has entries — and every answer was
    // null with no query behind it: the map was built disabled.
    expect(Customer::$hydrated)->not->toBeEmpty()
        ->and(Customer::$hydrated)->each->toBeNull()
        ->and($customerQueries)->toBe(0);
});

it('says which feeds the resolver hydrates under when it branches on the feed', function () {
    publishOrder();

    Storyfeed::feeds([
        'kitchen' => fn (FeedBuilder $feed) => $feed->only('confirm'),
        'customer' => fn (FeedBuilder $feed) => $feed->only('confirm'),
    ]);

    Customer::$hydrates = true;

    $finding = Storyfeed::doctor(['hydration'])->withCode('hydration.model')->sole();

    // The workbench Customer hydrates on every branch, so every feed is named;
    // the point of the assertion is that the feeds were probed at all.
    expect($finding->subject['feeds'])->toBe('an unnamed feed, kitchen, customer')
        ->and($finding->message)->toContain('under every feed');
});

it('says out loud when hydration is switched off', function () {
    publishOrder();
    Customer::$hydrates = true;

    config()->set('storyfeed.hydration.enabled', false);

    $finding = Storyfeed::doctor(['hydration'])->withCode('hydration.model')->sole();

    expect($finding->subject['enabled'])->toBeFalse()
        ->and($finding->message)->toContain('switched off');
});

it('estimates what the most recent page pays, per class present on it', function () {
    publishOrder();
    Customer::$hydrates = true;
    Delivery::$hydrates = true;

    $page = Storyfeed::doctor(['hydration'])->withCode('hydration.page')->sole();

    expect($page->severity)->toBe(Severity::Info)
        ->and($page->subject['queries'])->toBe(2)
        ->and($page->subject['aliases'])->toBe('delivery, customer')
        ->and($page->message)->toContain('2 hydration queries');
});

it('offers no page estimate when nothing hydrates', function () {
    publishOrder();

    expect(Storyfeed::doctor(['hydration'])->has('hydration.page'))->toBeFalse();
});

it('reports a resolver that throws on its own snapshot as opaque, never as clean', function () {
    Relation::morphMap(['throwing' => ThrowingResolver::class]);

    $row = ThrowingResolver::create(['name' => 'Throws']);

    Storyfeed::activity('confirm', $row)->publish();

    $report = Storyfeed::doctor(['hydration']);
    $finding = $report->withCode('hydration.opaque')->sole();

    expect($finding->severity)->toBe(Severity::Info)
        ->and($finding->subject['model'])->toBe(ThrowingResolver::class)
        ->and($finding->subject['exception'])->toBe(RuntimeException::class)
        ->and($report->isHealthy())->toBeTrue();
});

it('degrades when the tables are not there', function () {
    Customer::$hydrates = true;

    Schema::drop(config('storyfeed.tables.activities'));
    Schema::drop(config('storyfeed.tables.snapshots'));

    $report = Storyfeed::doctor(['hydration']);

    // Never throws; with no snapshot to probe, the strict Delivery resolver
    // is passed over, and Customer — which answers an empty probe — is named.
    expect($report->has('doctor.check_failed'))->toBeFalse()
        ->and($report->withCode('hydration.model')->sole()->subject['model'])->toBe(Customer::class)
        ->and($report->has('hydration.page'))->toBeFalse();
});
