<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedThread;
use Workbench\App\Models\Delivery;

/*
 * A thread and a publication date through the one-call form, from storage
 * to the node.
 */

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

it('records a publication date', function ($date) {
    $activity = Storyfeed::record(
        'confirm',
        object: Delivery::create(['tracking_number' => 'TN-1']),
        publishedAt: $date,
    );

    expect($activity->fresh()->published_at->toIso8601String())->toBe('2026-08-01T12:30:00+00:00');
})->with([
    'string' => '2026-08-01T12:30:00+00:00',
    'date object' => new DateTimeImmutable('2026-08-01T12:30:00+00:00'),
]);

it('accepts an explicit null thread and publication date', function () {
    $this->freezeSecond();

    $activity = Storyfeed::record(
        'confirm',
        object: Delivery::create(['tracking_number' => 'TN-1']),
        data: ['source' => 'import'],
        publishedAt: null,
        thread: null,
    );

    expect($activity->fresh()->data)->toBe(['source' => 'import'])
        ->and($activity->published_at->equalTo(now()))->toBeTrue()
        ->and(Storyfeed::feed()->get()->toArray()['items'][0]['thread'])->toBeNull();
});
