<?php

use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Delivery;

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

it('accepts an explicit null publication date', function () {
    $this->freezeSecond();

    $activity = Storyfeed::record(
        'confirm',
        object: Delivery::create(['tracking_number' => 'TN-1']),
        data: ['source' => 'import'],
        publishedAt: null,
    );

    expect($activity->fresh()->data)->toBe(['source' => 'import'])
        ->and($activity->published_at->equalTo(now()))->toBeTrue();
});
