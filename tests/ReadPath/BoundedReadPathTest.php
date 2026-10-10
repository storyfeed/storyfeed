<?php

use Illuminate\Support\Facades\Schema;
use Storyfeed\FeedBuilder;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Tests\Fixtures\ReadPathHistory;
use Storyfeed\Tests\Fixtures\ReadPathOracle;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

it('keeps complete payloads and cursors identical to the previous queries', function (bool $curate, string $mode) {
    config()->set('storyfeed.grouping.curate', $curate);
    ReadPathHistory::seed(400, days: 2);
    // Disturb the fixture with ties, future/removed members and missing snapshots.
    Activity::query()->whereIn('id', [21, 22, 23])->update(['published_at' => now()->subHour()]);
    Activity::query()->where('id', 44)->update(['deleted_at' => now()]);
    Activity::query()->where('id', 45)->update(['published_at' => now()->addDay()]);
    Activity::query()->where('id', 46)->update(['cached_actor_id' => null]);

    foreach ([
        fn ($feed) => $feed,
        fn ($feed) => $feed->actor(User::query()->first()),
        fn ($feed) => $feed->target(Customer::query()->first()),
        fn ($feed) => $feed->only('comment', 'upload'),
        fn ($feed) => $feed->query(fn ($q) => $q->where('verb', 'revise')->orWhere('verb', 'approve')),
    ] as $configure) {
        $cursor = null;
        $pages = 0;
        do {
            $expected = $configure((new ReadPathOracle)->{$mode}()->limit(7))->cursorPaginate(cursor: $cursor)->toArray();
            $actual = $configure((new FeedBuilder)->{$mode}()->limit(7))->cursorPaginate(cursor: $cursor)->toArray();
            expect(jsonObjectKeys($actual))->toBe(jsonObjectKeys($expected));
            $cursor = $actual['next_cursor'];
            $pages++;
        } while ($cursor !== null && $pages < 100);
        expect($cursor)->toBeNull();
    }
})->with([false, true])->with(['live']);

it('adds and removes the covering read indexes idempotently on configured tables', function () {
    $migration = include __DIR__.'/../../database/migrations/add_read_path_indexes_to_feed_groupings_table.php.stub';
    $migration->up();
    expect(Schema::hasIndex('feed_groupings', 'feed_groupings_activity_winner_index'))->toBeTrue()
        ->and(Schema::hasIndex('feed_groupings', 'feed_groupings_members_index'))->toBeTrue();
    $migration->down();
    $migration->down();
    expect(Schema::hasIndex('feed_groupings', 'feed_groupings_activity_winner_index'))->toBeFalse()
        ->and(Schema::hasIndex('feed_groupings', 'feed_groupings_members_index'))->toBeFalse();
    Schema::rename('feed_groupings', 'custom_groupings');
    config()->set('storyfeed.tables.groupings', 'custom_groupings');
    $migration->up();
    $migration->up();
    expect(Schema::hasIndex('custom_groupings', 'feed_groupings_activity_winner_index'))->toBeTrue()
        ->and(Schema::hasIndex('custom_groupings', 'feed_groupings_members_index'))->toBeTrue();
});

it('keeps independent row aliases for duplicate memberships and joined scopes', function () {
    ReadPathHistory::seed(400);
    Grouping::query()->whereIn('activity_id', [397, 398, 399, 400])->whereIn('bucket', ['repeat', 'object'])->update(['winner' => true]);
    foreach ([false, true] as $curate) {
        config()->set('storyfeed.grouping.curate', $curate);
        foreach (['live'] as $mode) {
            foreach ([false, true] as $joined) {
                $configure = fn ($feed) => $joined
                    ? $feed->query(fn ($query) => $query->whereNotNull('object_type')->select('verb')->crossJoinSub(DB::query()->selectRaw('1 as id')->unionAll(DB::query()->selectRaw('2 as id')), 'copies'))
                    : $feed;
                $expected = $configure((new ReadPathOracle)->{$mode}()->limit(7))->cursorPaginate()->toArray();
                $actual = $configure((new FeedBuilder)->{$mode}()->limit(7))->cursorPaginate()->toArray();
                expect(jsonObjectKeys($actual))->toBe(jsonObjectKeys($expected));
            }
        }
    }
});
