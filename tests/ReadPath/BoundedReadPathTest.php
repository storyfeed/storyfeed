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
            $expected = $configure((new ReadPathOracle)->{$mode}()->limit(7))->cursor($cursor)->get()->toArray();
            $actual = $configure((new FeedBuilder)->{$mode}()->limit(7))->cursor($cursor)->get()->toArray();
            expect(jsonObjectKeys($actual))->toBe(jsonObjectKeys($expected));
            $cursor = $actual['next_cursor'];
            $pages++;
        } while ($cursor !== null && $pages < 100);
        expect($cursor)->toBeNull();
    }
})->with([false, true])->with(['live', 'summary']);

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

it('preserves tuple identity, null ids and database collations in Summary counts', function () {
    ReadPathHistory::seed(400, days: 0);
    $hash = Grouping::query()->where('bucket', 'summary.day')->value('hash');
    $ids = Grouping::query()->where('bucket', 'summary.day')->where('hash', $hash)->pluck('activity_id');
    $identities = [['file', null], ['file', ''], ['a:1', 'x'], ['a', '1:x'], ['café', '1'], ['cafe', '1'], ['Case', 'x'], ['case', 'x'], ['case', 'x ']];
    expect($ids->count())->toBeGreaterThanOrEqual(count($identities));
    foreach ($identities as $i => [$type, $id]) {
        DB::table('feed_activities')->where('id', $ids[$i])->update(['origin_type' => $type, 'origin_id' => $id]);
    }
    $expected = (new ReadPathOracle)->summary()->limit(50)->get()->toArray();
    $actual = (new FeedBuilder)->summary()->limit(50)->get()->toArray();
    expect(jsonObjectKeys($actual))->toBe(jsonObjectKeys($expected));
});

it('keeps independent row aliases for duplicate memberships and joined scopes', function () {
    ReadPathHistory::seed(400);
    Grouping::query()->whereIn('activity_id', [397, 398, 399, 400])->whereIn('bucket', ['repeat', 'object'])->update(['winner' => true]);
    foreach ([false, true] as $curate) {
        config()->set('storyfeed.grouping.curate', $curate);
        foreach (['live', 'summary'] as $mode) {
            foreach ([false, true] as $joined) {
                $configure = fn ($feed) => $joined
                    ? $feed->query(fn ($query) => $query->whereNotNull('object_type')->select('verb')->crossJoinSub(DB::query()->selectRaw('1 as id')->unionAll(DB::query()->selectRaw('2 as id')), 'copies'))
                    : $feed;
                $expected = $configure((new ReadPathOracle)->{$mode}()->limit(7))->get()->toArray();
                $actual = $configure((new FeedBuilder)->{$mode}()->limit(7))->get()->toArray();
                expect(jsonObjectKeys($actual))->toBe(jsonObjectKeys($expected));
            }
        }
    }
});

it('keeps native grouping collations when a Summary hash has stored case variants', function () {
    ReadPathHistory::seed(400, days: 0);
    $hash = Grouping::query()->where('bucket', 'summary.day')->where('activity_id', 1)->value('hash');
    $uploads = Activity::query()->where('verb', 'upload')->pluck('id');
    Grouping::query()->where('bucket', 'summary.day')->where('hash', $hash)->whereIn('activity_id', $uploads)
        ->update(['hash' => strtoupper(substr($hash, 0, 4)).substr($hash, 4)]);
    $expected = (new ReadPathOracle)->summary()->limit(50)->get()->toArray();
    $actual = (new FeedBuilder)->summary()->limit(50)->get()->toArray();
    expect(jsonObjectKeys($actual))->toBe(jsonObjectKeys($expected));
});
