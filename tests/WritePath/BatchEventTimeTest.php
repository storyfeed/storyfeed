<?php

use Illuminate\Support\Facades\Event;
use Storyfeed\Actions\CloseBatches;
use Storyfeed\Events\BatchClosed;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Batch;

it('separates a drained backlog by publication time despite one wall-clock arrival moment', function () {
    foreach (['09:00', '09:05', '10:00', '11:00'] as $time) {
        Storyfeed::activity('ping')->actor('Importer')
            ->publishedAt(now()->setTimeFromTimeString($time))->publish();
    }

    $batches = Batch::query()->orderBy('opened_at')->get();

    expect($batches)->toHaveCount(3)
        ->and($batches->pluck('activities_count')->all())->toBe([2, 1, 1])
        ->and($batches[0]->opened_at->format('H:i'))->toBe('09:00')
        ->and($batches[0]->last_activity_at->format('H:i'))->toBe('09:05');
});

it('keeps the event-time high-water mark when members arrive out of order', function () {
    foreach (['09:00', '09:08', '09:02', '09:15'] as $time) {
        Storyfeed::activity('ping')->actor('Importer')
            ->publishedAt(now()->setTimeFromTimeString($time))->publish();
    }

    $batch = Batch::query()->sole();

    expect($batch->activities_count)->toBe(4)
        ->and($batch->opened_at->format('H:i'))->toBe('09:00')
        ->and($batch->last_activity_at->format('H:i'))->toBe('09:15');
});

it('opens an earlier window without closing or moving a later open window', function () {
    foreach (['11:00', '09:00', '09:05'] as $time) {
        Storyfeed::activity('ping')->actor('Importer')
            ->publishedAt(now()->setTimeFromTimeString($time))->publish();
    }

    $batches = Batch::query()->orderBy('opened_at')->get();

    expect($batches)->toHaveCount(2)
        ->and($batches->pluck('activities_count')->all())->toBe([2, 1])
        ->and($batches[0]->opened_at->format('H:i'))->toBe('09:00')
        ->and($batches[1]->opened_at->format('H:i'))->toBe('11:00')
        ->and($batches->every(fn ($batch) => $batch->isOpen()))->toBeTrue();
});

it('starts a separate batch for an arrival in an already closed window', function () {
    Storyfeed::activity('ping')->actor('Importer')
        ->publishedAt(now()->setTime(9, 0))->publish();
    (new CloseBatches)();
    $closed = Batch::query()->sole();
    $before = $closed->getRawOriginal();

    Storyfeed::activity('ping')->actor('Importer')
        ->publishedAt(now()->setTime(9, 2))->publish();

    expect(Batch::query()->count())->toBe(2)
        ->and($closed->fresh()->getRawOriginal())->toBe($before)
        ->and($closed->activities()->count())->toBe(1)
        ->and(Batch::query()->open()->sole()->opened_at->format('H:i'))->toBe('09:02');
});

it('closes at the wall clock while emitting the original event-time window', function () {
    Event::fake([BatchClosed::class]);

    foreach (['09:00', '09:10'] as $time) {
        Storyfeed::activity('ping')->actor('Importer')
            ->publishedAt(now()->setTimeFromTimeString($time))->publish();
    }

    $batches = Batch::query()->orderBy('opened_at')->get();

    // Exactly the quiet interval starts a new batch, just as before.
    expect($batches)->toHaveCount(2)
        ->and($batches[0]->closed_at->equalTo(now()))->toBeTrue()
        ->and($batches[1]->isOpen())->toBeTrue();

    expect((new CloseBatches)())->toBe(1)
        ->and($batches[1]->fresh()->closed_at->equalTo(now()))->toBeTrue();

    Event::assertDispatchedTimes(BatchClosed::class, 2);
    Event::assertDispatched(BatchClosed::class, fn ($event) => $event->batch->opened_at === now()->setTime(9, 0)->toIso8601String()
        && $event->batch->last_activity_at === now()->setTime(9, 0)->toIso8601String()
        && $event->batch->closed_at === now()->toIso8601String()
        && count($event->batch->activities) === 1);

});

it('does not rewrite a historical wall-clock batch when replaying earlier events', function () {
    Storyfeed::activity('ping')->actor('Importer')->publish();
    $legacy = Batch::query()->sole();
    $before = $legacy->getRawOriginal();

    Storyfeed::activity('ping')->actor('Importer')
        ->publishedAt(now()->subHours(3))->publish();

    expect(Batch::query()->count())->toBe(2)
        ->and($legacy->fresh()->getRawOriginal())->toBe($before)
        ->and($legacy->activities()->count())->toBe(1);
});
