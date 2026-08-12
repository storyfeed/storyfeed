<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Party;
use Storyfeed\Models\Snapshot;
use Storyfeed\StoryfeedManager;
use Storyfeed\Testing\StoryfeedFake;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('records instead of persisting', function () {
    Storyfeed::fake();

    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    expect(Activity::query()->count())->toBe(0);

    Storyfeed::assertPublished('confirm');
});

it('asserts against the object', function () {
    Storyfeed::fake();

    $one = Delivery::create(['tracking_number' => 'TN-1']);
    $two = Delivery::create(['tracking_number' => 'TN-2']);

    Storyfeed::activity('confirm', $one)->publish();

    Storyfeed::assertPublished('confirm', $one);
    Storyfeed::assertNotPublished('confirm', $two);
});

it('accepts an enum verb', function () {
    Storyfeed::fake();

    ActivityVerb::Confirm->publish(Delivery::create(['tracking_number' => 'TN-1']));

    Storyfeed::assertPublished(ActivityVerb::Confirm);
    Storyfeed::assertNotPublished(ActivityVerb::Upload);
});

it('accepts a closure matcher', function () {
    Storyfeed::fake();

    Storyfeed::record('sync', object: Delivery::create(['tracking_number' => 'TN-1']), actor: 'Concur');

    Storyfeed::assertPublished(fn (Activity $a) => $a->verb === 'sync' && $a->actor_type === 'storyfeed.party');
});

it('counts published activities', function () {
    Storyfeed::fake();

    Storyfeed::activity('ping')->publish();
    Storyfeed::activity('ping')->publish();
    Storyfeed::activity('pong')->publish();

    Storyfeed::assertPublishedCount(3);
    Storyfeed::assertPublishedCount(2, 'ping');
});

it('asserts nothing was published', function () {
    Storyfeed::fake();

    Storyfeed::assertNothingPublished();
});

it('exposes recorded activities', function () {
    Storyfeed::fake();

    Storyfeed::activity('ping')->publish();
    Storyfeed::activity('pong')->publish();

    expect(Storyfeed::published())->toHaveCount(2)
        ->and(Storyfeed::published('ping'))->toHaveCount(1);
});

it('still resolves the actor while faking', function () {
    $user = User::create(['name' => 'Sally', 'email' => 's@example.com']);
    $this->actingAs($user);

    Storyfeed::fake();

    $activity = Storyfeed::activity('ping')->publish();

    expect($activity->actor_type)->toBe('user')
        ->and($activity->actor_id)->toEqual($user->id);
});

it('inherits registries from the real manager', function () {
    Storyfeed::grammar(['delivery.confirm' => ':actor confirmed :object'])
        ->icons(['delivery.confirm' => 'bi-truck'])
        ->verbs(['confirm' => 'Update']);

    Storyfeed::fake();

    expect(Storyfeed::template('delivery', 'confirm'))->toBe(':actor confirmed :object')
        ->and(Storyfeed::icon('delivery', 'confirm'))->toBe('bi-truck')
        ->and(Storyfeed::activityType('confirm')?->value)->toBe('Update');
});

it('takes no side effects: no snapshots, groupings, or parties', function () {
    Storyfeed::fake();

    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    // The fixture's own InteractsWithFeed hook snapshots it on save; what
    // matters is that publishing adds nothing.
    $snapshots = Snapshot::query()->count();

    $activity = Storyfeed::activity('sync', $delivery)->actor('Concur')->publish();

    expect(Snapshot::query()->count())->toBe($snapshots)
        ->and(Grouping::query()->count())->toBe(0)
        ->and(Party::query()->count())->toBe(0)
        ->and($activity->actor_type)->toBe('storyfeed.party');
});

it('reuses a stubbed party across activities', function () {
    Storyfeed::fake();

    $one = Storyfeed::activity('sync')->actor('Concur')->publish();
    $two = Storyfeed::activity('sync')->actor('Concur')->publish();

    expect($one->actor_id)->toBe($two->actor_id)
        ->and(Party::query()->count())->toBe(0);
});

it('gives recorded activities a uid and published_at', function () {
    Storyfeed::fake();

    $activity = Storyfeed::activity('ping')->publish();

    expect($activity->uid)->toBeString()->toHaveLength(26)
        ->and($activity->published_at)->not->toBeNull();
});

it('is returned from fake() for direct use', function () {
    $fake = Storyfeed::fake();

    expect($fake)->toBeInstanceOf(StoryfeedFake::class);

    Storyfeed::activity('ping')->publish();

    $fake->assertPublished('ping');
});

it('inherits every manager registry, including ones added later', function () {
    Storyfeed::aggregateGrammar(['actors.upload' => ':actors uploaded :count files']);
    Storyfeed::grammar(['delivery.upload' => ':actor uploaded :object'])->icons(['delivery.upload' => 'bi-up']);

    $manager = app(StoryfeedManager::class);

    Storyfeed::fake();

    // Reflection over the REAL manager's properties: any registry a future
    // version adds must survive the swap — a field-by-field copy in
    // inheritFrom() once silently dropped the aggregate grammar, making a
    // fully-authored registry assert as 100% missing under the fake.
    $fake = app(StoryfeedManager::class);

    foreach ((new ReflectionClass(StoryfeedManager::class))->getProperties() as $property) {
        expect($property->getValue($fake))->toEqual(
            $property->getValue($manager),
            "StoryfeedFake did not inherit manager property \${$property->getName()}.",
        );
    }

    expect($fake->aggregateTemplate('actors', 'upload'))->toBe(':actors uploaded :count files');
});
