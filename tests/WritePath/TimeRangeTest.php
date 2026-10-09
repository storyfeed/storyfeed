<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Serialization\Reader;
use Storyfeed\Support\FeedItem;
use Workbench\App\Models\Delivery;

it('records a time range beside published_at', function () {
    $this->travelTo('2026-10-09 12:00:00');

    $activity = Storyfeed::activity('release', Delivery::create(['tracking_number' => 'v0.16']))
        ->anonymously()
        ->startsAt('2026-09-20 09:30:00')
        ->endsAt(Carbon::parse('2026-10-09 12:00:00'))
        ->publish()
        ->fresh();

    expect($activity->starts_at->toDateTimeString())->toBe('2026-09-20 09:30:00')
        ->and($activity->ends_at->toDateTimeString())->toBe('2026-10-09 12:00:00')
        ->and($activity->published_at->toDateTimeString())->toBe('2026-10-09 12:00:00');
});

it('leaves either end open', function () {
    $from = Storyfeed::activity('plan')->anonymously()->startsAt('2026-10-09')->publish()->fresh();
    $until = Storyfeed::activity('plan')->anonymously()->endsAt('2026-10-31')->publish()->fresh();
    $none = Storyfeed::activity('plan')->anonymously()->publish()->fresh();

    expect($from->starts_at)->not->toBeNull()->and($from->ends_at)->toBeNull()
        ->and($until->starts_at)->toBeNull()->and($until->ends_at)->not->toBeNull()
        ->and($none->starts_at)->toBeNull()->and($none->ends_at)->toBeNull();
});

it('refuses a range that ends before it starts, in either call order', function () {
    expect(fn () => Storyfeed::activity('plan')->startsAt('2026-10-09')->endsAt('2026-10-08'))
        ->toThrow(InvalidArgumentException::class, 'cannot end')
        ->and(fn () => Storyfeed::activity('plan')->endsAt('2026-10-08')->startsAt('2026-10-09'))
        ->toThrow(InvalidArgumentException::class, 'cannot end');
});

it('accepts a range of no length', function () {
    $activity = Storyfeed::activity('plan')->anonymously()->startsAt('2026-10-09')->endsAt('2026-10-09')->publish();

    expect($activity->starts_at->eq($activity->ends_at))->toBeTrue();
});

it('records a range through record()', function () {
    $activity = Storyfeed::record('plan', anonymous: true, startsAt: '2026-10-01', endsAt: '2026-10-09')->fresh();

    expect($activity->starts_at->toDateString())->toBe('2026-10-01')
        ->and($activity->ends_at->toDateString())->toBe('2026-10-09');
});

it('carries a range through the queue', function () {
    $pending = Storyfeed::activity('plan')->anonymously()->startsAt('2026-10-01 08:00:00')->endsAt('2026-10-02 08:00:00');

    $activity = unserialize(serialize($pending))->publish()->fresh();

    expect($activity->starts_at->toDateTimeString())->toBe('2026-10-01 08:00:00')
        ->and($activity->ends_at->toDateTimeString())->toBe('2026-10-02 08:00:00');
});

it('emits starts_at and ends_at on the activity node, null when absent', function () {
    $ranged = Storyfeed::activity('plan')->anonymously()->startsAt('2026-09-20 09:30:00')->publish()->fresh();
    $plain = Storyfeed::activity('plan')->anonymously()->publish()->fresh();
    $presenter = app(NodePresenter::class);

    expect($presenter->activityNode($ranged))
        ->toMatchArray(['starts_at' => '2026-09-20T09:30:00.000000Z', 'ends_at' => null])
        ->and($presenter->activityNode($plain))
        ->toMatchArray(['starts_at' => null, 'ends_at' => null]);
});

it('reads the range back from a feed item', function () {
    $item = new FeedItem(['kind' => 'activity', 'starts_at' => '2026-09-20T09:30:00.000000Z', 'ends_at' => null]);

    expect($item->startsAt()?->toIso8601ZuluString())->toBe('2026-09-20T09:30:00Z')
        ->and($item->endsAt())->toBeNull();
});

it('serializes startTime, endTime and a days-and-time duration', function () {
    $activity = Storyfeed::activity('plan')->anonymously()
        ->startsAt('2026-09-20 09:30:00.250000')
        ->endsAt('2026-10-09 14:00:05')
        ->publish();

    $document = serialize_one($activity);

    expect($document['startTime'])->toBe('2026-09-20T09:30:00Z')
        ->and($document['endTime'])->toBe('2026-10-09T14:00:05Z')
        ->and($document['duration'])->toBe('P19DT4H30M5S');
});

it('derives the duration in whole days and time', function (string $starts, string $ends, string $duration) {
    $activity = Storyfeed::activity('plan')->anonymously()->startsAt($starts)->endsAt($ends)->publish();

    expect(serialize_one($activity)['duration'])->toBe($duration);
})->with([
    'zero' => ['2026-10-09 12:00:00', '2026-10-09 12:00:00', 'PT0S'],
    'days only' => ['2026-10-01 00:00:00', '2026-11-01 00:00:00', 'P31D'],
    'time only' => ['2026-10-09 12:00:00', '2026-10-09 14:00:00', 'PT2H'],
    'minutes and seconds' => ['2026-10-09 12:00:00', '2026-10-09 12:01:30', 'PT1M30S'],
]);

it('omits duration when the range is open, and the range when there is none', function () {
    $open = serialize_one(Storyfeed::activity('plan')->anonymously()->startsAt('2026-10-09')->publish());
    $none = serialize_one(Storyfeed::activity('plan')->anonymously()->publish());

    expect($open)->toHaveKey('startTime')->not->toHaveKeys(['endTime', 'duration'])
        ->and($none)->not->toHaveKeys(['startTime', 'endTime', 'duration']);
});

it('reads startTime and endTime back from its own document', function () {
    $activity = Storyfeed::activity('plan')->anonymously()->startsAt('2026-09-20 09:30:00')->endsAt('2026-10-09 12:00:00')->publish();

    $parsed = app(Reader::class)->activity(json_decode((string) json_encode(serialize_one($activity)), true));

    expect($parsed['starts_at']?->utc()->toDateTimeString())->toBe('2026-09-20 09:30:00')
        ->and($parsed['ends_at']?->utc()->toDateTimeString())->toBe('2026-10-09 12:00:00');
});

it('adds nullable range columns on upgrade, idempotently and without a backfill', function () {
    $table = 'legacy_range_activities';
    config(['storyfeed.tables.activities' => $table]);

    Schema::create($table, function (Blueprint $blueprint) {
        $blueprint->id();
        $blueprint->string('verb');
    });
    DB::table($table)->insert(['id' => 1, 'verb' => 'historical']);
    $before = (array) DB::table($table)->first();
    $migration = include __DIR__.'/../../database/migrations/add_time_range_to_feed_activities_table.php.stub';

    $migration->up();
    $migration->up();

    expect((array) DB::table($table)->first())->toBe([...$before, 'starts_at' => null, 'ends_at' => null]);

    $migration->down();
    $migration->down();

    expect((array) DB::table($table)->first())->toBe($before);
});
