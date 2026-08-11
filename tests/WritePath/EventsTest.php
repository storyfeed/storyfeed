<?php

use Illuminate\Support\Facades\Event;
use Storyfeed\Events\ActivityDeleted;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Delivery;

it('dispatches ActivityPublished after publish', function () {
    Event::fake([ActivityPublished::class]);

    $activity = Storyfeed::activity()->verb('ping')->publish();

    Event::assertDispatched(
        ActivityPublished::class,
        fn (ActivityPublished $event) => $event->activity->is($activity),
    );
});

it('dispatches ActivityDeleted when an activity is deleted', function () {
    Event::fake([ActivityDeleted::class]);

    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $activity = Storyfeed::activity()->confirm($delivery)->publish();

    $activity->delete();

    Event::assertDispatched(
        ActivityDeleted::class,
        fn (ActivityDeleted $event) => $event->activity->is($activity),
    );
});
