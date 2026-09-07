<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedThread;
use Workbench\App\Models\Delivery;
use Workbench\App\Stories\DeliveryWasConfirmed;

beforeEach(function () {
    Storyfeed::stories([DeliveryWasConfirmed::class]);
});

it('records a story thread through storage into the node without exposing its version', function () {
    $payload = [
        'text' => 'Thursday works.',
        'by' => 'Sally',
        'kind' => 'replied',
        'replies' => 2,
        'truncated' => true,
    ];

    $activity = DeliveryWasConfirmed::record(
        object: Delivery::create(['tracking_number' => 'TN-1']),
        data: ['source' => 'import'],
        thread: FeedThread::make(...$payload),
    );

    expect($activity->fresh()->data)->toBe([
        'source' => 'import',
        FeedThread::KEY => [...$payload, '$v' => 1],
    ]);

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($node['thread'])->toBe($payload)
        ->and($node['thread'])->not->toHaveKey('$v')
        ->and($node['data'])->toBe(['source' => 'import']);
});

it('records a story publication date', function ($date) {
    $activity = DeliveryWasConfirmed::record(
        object: Delivery::create(['tracking_number' => 'TN-1']),
        publishedAt: $date,
    );

    expect($activity->fresh()->published_at->toIso8601String())->toBe('2026-08-01T12:30:00+00:00');
})->with([
    'string' => '2026-08-01T12:30:00+00:00',
    'date object' => new DateTimeImmutable('2026-08-01T12:30:00+00:00'),
]);

it('keeps existing positional story record calls unchanged', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $original = DeliveryWasConfirmed::record($delivery);
    $this->freezeSecond();

    $activity = DeliveryWasConfirmed::record($delivery, 'Sally', 'Warehouse', 'Import', ['source' => 'import'], true, []);

    expect($activity->fresh()->data)->toBe(['source' => 'import'])
        ->and($activity->object_id)->toEqual($delivery->id)
        ->and($activity->actor->name)->toBe('Sally')
        ->and($activity->target->name)->toBe('Warehouse')
        ->and($activity->context->name)->toBe('Import')
        ->and($activity->published_at->equalTo(now()))->toBeTrue()
        ->and($original->fresh()->trashed())->toBeTrue();

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($node['thread'])->toBeNull()
        ->and($node['data'])->toBe(['source' => 'import']);
});

it('accepts explicit null story thread and publication date', function () {
    $this->freezeSecond();

    $activity = DeliveryWasConfirmed::record(
        object: Delivery::create(['tracking_number' => 'TN-1']),
        data: ['source' => 'import'],
        publishedAt: null,
        thread: null,
    );

    expect($activity->fresh()->data)->toBe(['source' => 'import'])
        ->and($activity->published_at->equalTo(now()))->toBeTrue()
        ->and(Storyfeed::feed()->get()->toArray()['items'][0]['thread'])->toBeNull();
});
