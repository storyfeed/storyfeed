<?php

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Regressions from the Newsroom's FRICTION.md (journal 014) — each of these
 * was found by building a real consumer app, not by the suite.
 */

it('publishes with a timestamp even when model events are muted', function () {
    // The starter kit's DatabaseSeeder ships with WithoutModelEvents; the
    // creating-hook stamp never fires, and every seeded activity used to
    // persist published_at = NULL — silently invisible to every feed.
    $activity = Model::withoutEvents(
        fn () => Storyfeed::activity()->verb('ping')->publish(),
    );

    expect($activity->published_at)->not->toBeNull()
        ->and(Storyfeed::feed()->get()->toArray()['items'])->toHaveCount(1);
});

it('never calls toFeedLink for un-snapshotted entities', function () {
    Delivery::$feedLinkCalls = 0;

    Activity::query()->create([
        'verb' => 'confirm',
        'object_type' => 'delivery',
        'object_id' => 999,
        'published_at' => now(),
    ]);

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    // The contract promises degraded entities arrive with url: null; calling
    // the app's toFeedLink([]) makes every naive implementation warn.
    expect(Delivery::$feedLinkCalls)->toBe(0)
        ->and($item['object']['url'])->toBeNull();
});

it('supports the conditionable idiom on the feed builder', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    Storyfeed::activity()->actor($user)->verb('ping')->publish();

    $items = Storyfeed::feed()
        ->when(true, fn ($feed) => $feed->actor($user))
        ->unless(true, fn ($feed) => $feed->verb('nope'))
        ->get()
        ->toArray()['items'];

    expect($items)->toHaveCount(1);
});

it('reads the envelope with array access', function () {
    Storyfeed::activity()->verb('ping')->publish();

    $page = Storyfeed::feed()->get();

    expect($page['payload_version'])->toBe(1)
        ->and($page['items'])->toHaveCount(1)
        ->and($page['next_cursor'])->toBeNull()
        ->and(fn () => $page['items'] = [])->toThrow(LogicException::class);
});

it('exposes the snapshot backlog as a builder scope', function () {
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    Activity::query()->create([
        'verb' => 'confirm',
        'object_type' => 'delivery',
        'object_id' => 999,
        'published_at' => now(),
    ]);

    expect(Activity::query()->uncached()->count())->toBe(1);
});
