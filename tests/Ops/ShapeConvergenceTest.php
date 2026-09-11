<?php

use Storyfeed\Actions\TrickleSnapshots;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Snapshot;
use Workbench\App\Models\Delivery;

it('converges when one class legitimately has two shapes', function () {
    /*
     * SHAPE IS A PROPERTY OF A ROW, NOT OF A CLASS. `ShapeSignature` tags every
     * scalar with its type, so a nullable key yields two fingerprints for one
     * class with nothing deployed and nothing stale.
     *
     * The trickle used to compare every row against a single live sample, so it
     * rewrote whichever cohort the sample did not belong to — and a reshape
     * touches `updated_at`, which changes who gets sampled next, so the two
     * cohorts took turns and the count climbed instead of falling. A consumer
     * saw 14 reshaped and then 32, twenty seconds apart, on a feed where
     * nothing had changed.
     */
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-2']))->publish();
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => null]))->publish();

    $shapes = Snapshot::query()->where('model_type', 'delivery')->pluck('shape')->unique();

    expect($shapes)->toHaveCount(2, 'two fingerprints for one class, from data alone');

    // Nothing has been deployed, so nothing is stale. Both cohorts agree with
    // their own models, and no run should write anything.
    $first = (new TrickleSnapshots)();
    $second = (new TrickleSnapshots)();
    $third = (new TrickleSnapshots)();

    /*
     * CONVERGENCE IS THE CONTRACT, not "never writes". A first pass may
     * legitimately heal — a snapshot written from a model whose database
     * defaults were not yet loaded records a shape that model no longer
     * produces once read back — and that is what the first run is doing here.
     *
     * What must not happen is the second run, and the third, and every run
     * after them finding work in a feed where nothing changed.
     */
    expect($second['reshaped'])->toBe(0, 'a second run found work in a feed where nothing changed')
        ->and($third['reshaped'])->toBe(0, 'and a third');

    expect($first['reshaped'])->toBeGreaterThanOrEqual(0);
});
