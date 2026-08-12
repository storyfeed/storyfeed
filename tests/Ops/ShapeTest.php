<?php

use Storyfeed\Actions\TrickleSnapshots;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\SyncToken;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

beforeEach(function () {
    Delivery::$extendedFeedShape = false;
    Delivery::$feedShapeVersion = 1;
});

afterEach(function () {
    Delivery::$extendedFeedShape = false;
    Delivery::$feedShapeVersion = 1;
});

it('stamps every snapshot with a shape fingerprint', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $snapshot = Snapshot::query()->where('model_type', 'delivery')->sole();

    expect($snapshot->shape)->toMatch('/^[0-9a-f]{40}$/');
});

it('heals shape-stale snapshots through the trickle, touching only the changed type', function () {
    $customer = Customer::create(['name' => 'Acme Co.']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity('confirm', Delivery::create(['tracking_number' => "TN-{$i}"]))->for($customer)->publish();
    }

    $customerShape = Snapshot::query()->where('model_type', 'customer')->sole()->shape;

    // "Deploy" a DTO change: delivery data gains a nested carrier block.
    Delivery::$extendedFeedShape = true;

    // Doctor sees nothing yet (all stored fingerprints still agree)…
    // the trickle samples a LIVE model, so it does.
    $result = (new TrickleSnapshots)();

    expect($result['reshaped'])->toBe(3)
        ->and(Snapshot::query()->where('model_type', 'delivery')->get())
        ->each(fn ($s) => $s->data->toHaveKey('carrier'))
        ->and(Snapshot::query()->where('model_type', 'customer')->sole()->shape)->toBe($customerShape);

    // Converged: a second run reshapes nothing.
    expect((new TrickleSnapshots)()['reshaped'])->toBe(0);
});

it('treats a declared version bump as staleness even when keys are identical', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $before = Snapshot::query()->where('model_type', 'delivery')->sole()->shape;

    // Same keys, changed MEANING — the escape hatch for what no structural
    // fingerprint can see (e.g. a label format change).
    Delivery::$feedShapeVersion = 2;

    expect((new TrickleSnapshots)()['reshaped'])->toBe(1)
        ->and(Snapshot::query()->where('model_type', 'delivery')->sole()->shape)->not->toBe($before);
});

it('respects the run limit across both trickle phases', function () {
    foreach (range(1, 5) as $i) {
        Storyfeed::activity('confirm', Delivery::create(['tracking_number' => "TN-{$i}"]))->publish();
    }

    Delivery::$extendedFeedShape = true;

    $result = (new TrickleSnapshots)(3);

    expect($result['reshaped'])->toBe(3);

    // The remainder converges on subsequent runs — self-healing, paced.
    expect((new TrickleSnapshots)(10)['reshaped'])->toBe(2);
});

it('reports mixed shapes in doctor after the drift is written', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    Delivery::$extendedFeedShape = true;

    // A new publish under the new shape → mixed fingerprints in the table.
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-2']))->publish();

    $this->artisan('storyfeed:doctor')
        ->expectsOutputToContain('Snapshots of `delivery` carry mixed shape fingerprints')
        ->assertSuccessful();

    (new TrickleSnapshots)();

    $this->artisan('storyfeed:doctor')
        ->doesntExpectOutputToContain('mixed shape fingerprints')
        ->assertSuccessful();
});

it('does not bump the sync token when healing shapes', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    Delivery::$extendedFeedShape = true;

    (new TrickleSnapshots)();

    // Entity-data freshening changes no node ids: not a resync event.
    expect(SyncToken::current())->toBeNull();
});
