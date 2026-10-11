<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Batch;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\PendingTombstone;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * #100: an app's own metadata on a batch or a tombstone, written with
 * ->meta([...]) and stored as free-form JSON. Keys starting with
 * `storyfeed.` are core's. Compared with toEqual: MySQL's JSON type
 * stores an object's keys in its own order.
 */

beforeEach(function () {
    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
});

afterEach(function () {
    Delivery::$tombstone = null;
});

it('writes meta to the batch the activity joins, each activity adding to it', function () {
    Storyfeed::activity('import', Delivery::create(['tracking_number' => 'TN-1']))
        ->actor($this->ines)
        ->meta(['source' => 'csv'])
        ->meta(['file' => 'march.csv'])
        ->publish();

    expect(Batch::sole()->meta)->toEqual(['source' => 'csv', 'file' => 'march.csv']);

    Storyfeed::activity('import', Delivery::create(['tracking_number' => 'TN-2']))
        ->actor($this->ines)
        ->meta(['file' => 'april.csv', 'rows' => 2])
        ->publish();

    expect(Batch::sole()->meta)->toEqual(['source' => 'csv', 'file' => 'april.csv', 'rows' => 2]);

    Storyfeed::activity('import', Delivery::create(['tracking_number' => 'TN-3']))->actor($this->ines)->publish();

    expect(Batch::sole()->meta)->toEqual(['source' => 'csv', 'file' => 'april.csv', 'rows' => 2]);
});

it('takes meta on record() and a verb enum', function () {
    Storyfeed::record('import', Delivery::create(['tracking_number' => 'TN-1']), actor: $this->ines, meta: ['source' => 'api']);

    expect(Batch::sole()->meta)->toEqual(['source' => 'api']);

    ActivityVerb::Confirm->meta(['by' => 'enum'])->actor($this->ines)->object(Delivery::create(['tracking_number' => 'TN-2']))->publish();
    ActivityVerb::Confirm->record(Delivery::create(['tracking_number' => 'TN-3']), actor: $this->ines, meta: ['and' => 'record']);

    expect(Batch::sole()->meta)->toEqual(['source' => 'api', 'by' => 'enum', 'and' => 'record']);
});

it('leaves a batch\'s meta null until an activity writes some', function () {
    Storyfeed::activity('import', Delivery::create(['tracking_number' => 'TN-1']))->actor($this->ines)->publish();

    expect(Batch::sole()->meta)->toBeNull();
});

it('drops meta on an activity that joins no batch', function () {
    Storyfeed::activity('import', Delivery::create(['tracking_number' => 'TN-1']))->anonymously()->meta(['source' => 'csv'])->publish();

    expect(Batch::query()->count())->toBe(0);
});

it('writes meta to the tombstone, and a force delete adds to it', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::activity('confirm', $delivery)->actor($this->ines)->publish();

    Delivery::$tombstone = fn (PendingTombstone $tombstone) => $tombstone->meta(['reason' => 'duplicate', 'by' => 'ines']);
    $delivery->delete();

    expect(FeedTombstone::sole()->meta)->toEqual(['reason' => 'duplicate', 'by' => 'ines'])
        ->and(FeedTombstone::sole()->label)->toBeNull();

    Delivery::$tombstone = fn (PendingTombstone $tombstone) => $tombstone->keepLabel()->meta(['by' => 'gdpr-job', 'erased' => true]);
    $delivery->forceDelete();

    expect(FeedTombstone::sole()->meta)->toEqual(['reason' => 'duplicate', 'by' => 'gdpr-job', 'erased' => true])
        ->and(FeedTombstone::sole()->label)->toBe('Delivery #TN-1');
});

it('reserves the storyfeed. key prefix', function (Closure $write) {
    expect($write)->toThrow(InvalidArgumentException::class, 'The meta key [storyfeed.route_key] starts with [storyfeed.], which Storyfeed reserves for itself. Name it without the prefix.');
})->with([
    'an activity' => [fn () => Storyfeed::activity('import')->meta(['storyfeed.route_key' => 'x'])],
    'record()' => [fn () => Storyfeed::record('import', meta: ['source' => 'csv', 'storyfeed.route_key' => 'x'])],
    'a tombstone' => [fn () => (new PendingTombstone)->meta(['storyfeed.route_key' => 'x'])],
]);

it('allows a key that only contains storyfeed', function () {
    expect((new PendingTombstone)->meta(['storyfeed' => 1, 'my.storyfeed.key' => 2])->tombstoneMeta())->toBe(['storyfeed' => 1, 'my.storyfeed.key' => 2]);
});
