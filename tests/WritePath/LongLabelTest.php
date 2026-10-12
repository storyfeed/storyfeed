<?php

use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Models\FeedTombstone;
use Workbench\App\Models\Delivery;

/*
 * `label` is varchar(255) on snapshots and tombstones, and a model's label is
 * whatever its app makes it (a poll option can run to 500 characters). PostgreSQL
 * and MySQL refuse an over-long value and fail the write; SQLite stores it, so
 * these assert the stored length rather than waiting for a throw.
 */
it('fits a long label into the snapshot column', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $delivery->tracking_number = str_repeat('é', 500);

    $label = (new SnapshotEntity)($delivery)->fresh()->label;

    expect(mb_strlen($label))->toBe(255)
        ->and($label)->toStartWith('Delivery #éé')
        ->and($label)->toEndWith('…');
});

it('leaves a label that fits alone', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $delivery->tracking_number = str_repeat('x', 245);

    expect((new SnapshotEntity)($delivery)->fresh()->label)->toBe('Delivery #'.str_repeat('x', 245));
});

it('fits a long label into the tombstone column', function () {
    $tombstone = FeedTombstone::create([
        'model_type' => 'delivery',
        'model_id' => '1',
        'label' => str_repeat('a', 500),
        'deleted_at' => now(),
    ]);

    expect(mb_strlen($tombstone->fresh()->label))->toBe(255);
});
