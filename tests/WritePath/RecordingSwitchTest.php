<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Exceptions\UnauthoredActivity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\Models\Snapshot;
use Storyfeed\PendingStory;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Events\DeliveryConfirmed;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveryWasConfirmed;

/*
 * The recording switch (GH #1). A consumer's parallel suite deadlocked on
 * Postgres autovacuum over feed_snapshots because every test that touched
 * anything publishing wrote to seven tables. The answer is a switch with
 * knobs — NOT an environment-flipped default — so every entry point here is
 * exercised with recording off and must write nothing and throw nothing.
 */

/** @return array<string, int> */
function feedRowCounts(): array
{
    $counts = [];

    foreach (config('storyfeed.tables') as $key => $table) {
        $counts[$key] = DB::table($table)->count();
    }

    return $counts;
}

function expectNoFeedRows(): void
{
    expect(array_filter(feedRowCounts()))->toBe([]);
}

describe('config off', function () {
    beforeEach(fn () => config()->set('storyfeed.recording.enabled', false));

    it('writes nothing and throws nothing from the fluent builder', function () {
        $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        $activity = Storyfeed::activity()->actor($user)->verb('confirm', $delivery)->publish();

        expect($activity)->toBeInstanceOf(Activity::class)
            ->and($activity->exists)->toBeFalse()
            ->and($activity->id)->toBeNull()
            ->and($activity->verb)->toBe('confirm')
            ->and($activity->actor_type)->toBe('user')
            ->and($activity->uid)->toBeString()->toHaveLength(26)
            ->and($activity->published_at)->not->toBeNull();

        expectNoFeedRows();
    });

    it('writes nothing from Storyfeed::record()', function () {
        $activity = Storyfeed::record('confirm', object: Delivery::create(['tracking_number' => 'TN-1']));

        expect($activity->exists)->toBeFalse();

        expectNoFeedRows();
    });

    it('writes nothing from a verb enum', function () {
        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        expect(ActivityVerb::Confirm->record($delivery)->exists)->toBeFalse()
            ->and(ActivityVerb::Confirm->publish($delivery)->exists)->toBeFalse()
            ->and(ActivityVerb::Confirm->actor('Ops')->object($delivery)->publish()->exists)->toBeFalse();

        expectNoFeedRows();
    });

    it('writes nothing from a Story', function () {
        Storyfeed::stories([DeliveryWasConfirmed::class]);

        $activity = PendingStory::of(DeliveryWasConfirmed::class)
            ->object(Delivery::create(['tracking_number' => 'TN-1']))
            ->publish();

        expect($activity->exists)->toBeFalse()->and($activity->verb)->toBe('confirm');

        expectNoFeedRows();
    });

    it('writes nothing from a PublishesToFeed event', function () {
        Storyfeed::stories([DeliveryWasConfirmed::class]);

        DeliveryConfirmed::dispatch(
            Delivery::create(['tracking_number' => 'TN-1']),
            User::create(['name' => 'Sally', 'email' => 'sally@example.com']),
            Customer::create(['name' => 'Acme Co.']),
        );

        expectNoFeedRows();
    });

    it('writes nothing for a composite', function () {
        $files = [Delivery::create(['tracking_number' => 'A']), Delivery::create(['tracking_number' => 'B'])];

        $parent = Storyfeed::activity('upload')->objects($files)->publish();

        expect($parent->exists)->toBeFalse()->and($parent->object_type)->toBeNull();

        expectNoFeedRows();
    });

    it('does not insert a party for a string actor or an as() scope', function () {
        // Parties resolve at association time, before publish() can decline:
        // the switch has to reach them or a muted suite still writes.
        $activity = Storyfeed::activity('sync', Delivery::create(['tracking_number' => 'TN-1']))
            ->actor('Concur Web Service')
            ->publish();

        Storyfeed::as('System', fn () => Storyfeed::activity('ping')->publish());

        expect($activity->actor_type)->toBe('storyfeed.party')
            ->and($activity->actor_id)->toBeNull()
            ->and(Party::query()->count())->toBe(0);

        expectNoFeedRows();
    });

    it('still finds a party that already exists, without writing', function () {
        $existing = Storyfeed::recording(fn () => Party::make('Concur'));

        expect(Storyfeed::party('Concur')->is($existing))->toBeTrue()
            ->and(Party::query()->count())->toBe(1);
    });

    it('stops refreshing snapshots when Feedable models are saved', function () {
        // The lifecycle hook, not publish(): feed_snapshots is the churn table
        // in the consumer's report because this fires on EVERY save.
        Delivery::create(['tracking_number' => 'TN-1'])->update(['tracking_number' => 'TN-2']);
        User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

        expect(Snapshot::query()->count())->toBe(0);
    });

    it('still refreshes a snapshot when asked to explicitly', function () {
        Delivery::create(['tracking_number' => 'TN-1'])->updateFeedSnapshot();

        expect(Snapshot::query()->count())->toBe(1);
    });

    it('dispatches no ActivityPublished', function () {
        Event::fake([ActivityPublished::class]);

        Storyfeed::activity('ping')->publish();

        Event::assertNotDispatched(ActivityPublished::class);
    });

    it('still runs the development-time assertions — muted is not blind', function () {
        config()->set('storyfeed.grammar.strict', true);

        expect(fn () => Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish())
            ->toThrow(UnauthoredActivity::class);
    });

    it('leaves reads untouched', function () {
        Storyfeed::recording(fn () => Storyfeed::activity('ping')->publish());

        expect(Storyfeed::feed()->get()->toArray()['items'])->toHaveCount(1);
    });

    it('is reported by isRecording()', function () {
        expect(Storyfeed::isRecording())->toBeFalse();
    });
});

describe('runtime toggles', function () {
    it('records by default in every environment, including testing', function () {
        expect(app()->environment())->toBe('testing')
            ->and(config('storyfeed.recording.enabled'))->toBeTrue()
            ->and(Storyfeed::isRecording())->toBeTrue()
            ->and(Storyfeed::activity('ping')->publish()->exists)->toBeTrue();
    });

    it('stops and starts, overriding config either way', function () {
        Storyfeed::stopRecording();

        expect(Storyfeed::isRecording())->toBeFalse()
            ->and(Storyfeed::activity('ping')->publish()->exists)->toBeFalse();

        config()->set('storyfeed.recording.enabled', false);

        Storyfeed::startRecording();

        expect(Storyfeed::isRecording())->toBeTrue()
            ->and(Storyfeed::activity('ping')->publish()->exists)->toBeTrue()
            ->and(Activity::query()->count())->toBe(1);
    });

    it('scopes a closure without recording and restores the previous state', function () {
        $result = Storyfeed::withoutRecording(function () {
            expect(Storyfeed::activity('ping')->publish()->exists)->toBeFalse();

            return 'done';
        });

        expect($result)->toBe('done')
            ->and(Storyfeed::isRecording())->toBeTrue()
            ->and(Storyfeed::activity('ping')->publish()->exists)->toBeTrue();
    });

    it('scopes a closure with recording on and restores the previous state', function () {
        config()->set('storyfeed.recording.enabled', false);

        $activity = Storyfeed::recording(fn () => Storyfeed::activity('ping')->publish());

        expect($activity->exists)->toBeTrue()
            ->and(Storyfeed::isRecording())->toBeFalse()
            ->and(Storyfeed::activity('ping')->publish()->exists)->toBeFalse();
    });

    it('restores the previous state when the closure throws', function () {
        expect(fn () => Storyfeed::withoutRecording(fn () => throw new RuntimeException('boom')))
            ->toThrow(RuntimeException::class);

        expect(Storyfeed::isRecording())->toBeTrue();

        Storyfeed::stopRecording();

        expect(fn () => Storyfeed::recording(fn () => throw new RuntimeException('boom')))
            ->toThrow(RuntimeException::class);

        expect(Storyfeed::isRecording())->toBeFalse();
    });

    it('restores the PREVIOUS state, not the opposite one, when nested', function () {
        // A withoutRecording() inside a muted suite must leave the suite muted.
        Storyfeed::stopRecording();

        Storyfeed::withoutRecording(fn () => null);

        expect(Storyfeed::isRecording())->toBeFalse();

        Storyfeed::startRecording();

        Storyfeed::recording(fn () => null);

        expect(Storyfeed::isRecording())->toBeTrue();
    });

    it('does not leak between tests', function () {
        // The previous tests in this file stopped recording; a fresh
        // application per test means this one never sees it.
        expect(Storyfeed::isRecording())->toBeTrue();
    });
});

describe('with the fake', function () {
    it('still captures when recording is off — the fake outranks the switch', function () {
        config()->set('storyfeed.recording.enabled', false);

        Storyfeed::fake();

        $delivery = Delivery::create(['tracking_number' => 'TN-1']);

        Storyfeed::record('confirm', object: $delivery, actor: 'Concur');

        Storyfeed::assertPublished('confirm', $delivery);

        expectNoFeedRows();
    });

    it('still captures after stopRecording()', function () {
        Storyfeed::stopRecording();
        Storyfeed::fake();

        Storyfeed::activity('ping')->publish();

        Storyfeed::assertPublishedCount(1);
    });
});
