<?php

use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\FeedEntity;
use Storyfeed\Models\Snapshot;
use Workbench\App\Models\Delivery;

it('does not regress a newer snapshot when an older model copy arrives last', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1', 'status' => 'draft']);
    $older = $delivery->fresh();
    $this->travel(1)->minutes();
    $delivery->update(['tracking_number' => 'TN-2', 'status' => 'shipped']);

    $newer = (new SnapshotEntity)($delivery);
    $result = (new SnapshotEntity)($older);

    expect($result->id)->toBe($newer->id)
        ->and($result->fresh()->label)->toBe('Delivery #TN-2')
        ->and($result->fresh()->data['status'])->toBe('shipped');
});

it('allows equal source times to refresh and does not compare snapshot write time', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $this->travel(1)->days();
    (new SnapshotEntity)($delivery);

    $delivery->tracking_number = 'TN-2';
    expect((new SnapshotEntity)($delivery)->label)->toBe('Delivery #TN-2');

    $delivery->updated_at = $delivery->updated_at->addMinute();
    $delivery->tracking_number = 'TN-3';
    expect((new SnapshotEntity)($delivery)->label)->toBe('Delivery #TN-3');
});

it('still snapshots models with unusable source timestamps', function ($timestamp) {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $attributes = $delivery->getAttributes();

    if ($timestamp === 'missing') {
        unset($attributes['updated_at']);
    } else {
        $attributes['updated_at'] = $timestamp;
    }

    $delivery->setRawAttributes($attributes);
    $delivery->tracking_number = 'TN-2';
    $snapshot = (new SnapshotEntity)($delivery);

    expect($snapshot->fresh()->label)->toBe('Delivery #TN-2')
        ->and($snapshot->source_updated_at)->toBeNull();
})->with([null, 'missing', 'not-a-timestamp']);

it('still snapshots timestamp-disabled models', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $delivery->timestamps = false;
    $delivery->tracking_number = 'TN-2';

    expect((new SnapshotEntity)($delivery)->label)->toBe('Delivery #TN-2');
});

it('orders custom source timestamps in UTC with subsecond precision and protects every body field', function () {
    $model = new class extends Delivery
    {
        public const UPDATED_AT = 'modified_at';

        public function getMorphClass(): string
        {
            return 'delivery';
        }

        public function toFeed(): FeedEntity
        {
            return FeedEntity::make(
                label: $this->tracking_number,
                data: ['status' => $this->status],
                component: $this->status,
                content: $this->status,
                mediaType: $this->status,
                attributedTo: $this->status,
            );
        }
    };
    $model->setRawAttributes(['id' => 99, 'tracking_number' => 'new', 'status' => 'new', 'modified_at' => '2026-08-01T14:00:00.900000+02:00']);
    $newer = (new SnapshotEntity)($model);
    $older = clone $model;
    $older->setRawAttributes(['id' => 99, 'tracking_number' => 'old', 'status' => 'old', 'modified_at' => '2026-08-01T12:00:00.100000+00:00']);

    $result = (new SnapshotEntity)($older);

    expect($result->fresh()->only(['label', 'component', 'data', 'content', 'media_type', 'attributed_to', 'shape', 'source_updated_at']))
        ->toBe($newer->fresh()->only(['label', 'component', 'data', 'content', 'media_type', 'attributed_to', 'shape', 'source_updated_at']));
});

it('adds a nullable source timestamp to existing snapshots without a backfill', function () {
    $migration = include __DIR__.'/../../database/migrations/add_source_updated_at_to_feed_snapshots_table.php.stub';
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $migration->down();
    $migration->down();
    $migration->up();
    $migration->up();

    $snapshot = Snapshot::query()->sole();
    expect($snapshot->source_updated_at)->toBeNull()
        ->and($snapshot->label)->toBe('Delivery #TN-1');

    $delivery->tracking_number = 'TN-2';
    expect((new SnapshotEntity)($delivery)->label)->toBe('Delivery #TN-2');
});
