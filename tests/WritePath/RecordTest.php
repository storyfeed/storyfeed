<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedThread;
use Workbench\App\Models\Delivery;

it('records a thread through storage into the node without exposing its version', function () {
    $payload = [
        'text' => 'Thursday works.',
        'by' => 'Sally',
        'kind' => 'replied',
        'replies' => 2,
        'truncated' => true,
    ];

    $activity = Storyfeed::record(
        'confirm',
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

it('keeps existing positional record calls unchanged without a thread', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $date = now()->subDays(3)->startOfSecond();

    $activity = Storyfeed::record('confirm', $delivery, 'Sally', 'Warehouse', 'Import', ['source' => 'import'], $date, false, []);

    expect($activity->fresh()->data)->toBe(['source' => 'import'])
        ->and($activity->object_id)->toEqual($delivery->id)
        ->and($activity->actor->name)->toBe('Sally')
        ->and($activity->target->name)->toBe('Warehouse')
        ->and($activity->context->name)->toBe('Import')
        ->and($activity->published_at->equalTo($date))->toBeTrue();

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($node['thread'])->toBeNull()
        ->and($node['data'])->toBe(['source' => 'import']);
});

it('accepts an explicit null thread without adding stored thread data', function () {
    $activity = Storyfeed::record(
        'confirm',
        object: Delivery::create(['tracking_number' => 'TN-1']),
        data: ['source' => 'import'],
        thread: null,
    );

    expect($activity->fresh()->data)->toBe(['source' => 'import'])
        ->and(Storyfeed::feed()->get()->toArray()['items'][0]['thread'])->toBeNull();
});
