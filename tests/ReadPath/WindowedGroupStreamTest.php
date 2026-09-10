<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * FeedBuilder::groupStream() aggregates over a WINDOW of recent history and
 * widens when the window comes up short (W114, 2026-09-09), instead of
 * aggregating every eligible activity on every page. The argument for why a
 * windowed page is identical to the unbounded one is in groupStream()'s
 * comment; arguments rot, so this file pins the behaviour.
 *
 * The oracle is the unbounded aggregate itself — the query exactly as it ran
 * before — reached by overriding windowDepths() to [null]. Every test walks
 * the whole feed, page by page, through both and asserts every page is the
 * same: same items in the same order, same counts, same children, same
 * distinct blocks, same cursors. The fixture is randomized under a fixed seed
 * so the shapes that matter occur by volume rather than by hand: groups
 * straddling a window floor, groups whose newest member sits exactly on a
 * cursor, ties at one instant, solos between groups, members hidden by
 * soft-delete or a future publish date, uncurated fallbacks.
 *
 * The depths are tiny so the window actually engages and actually widens on
 * a fixture this size; the tests assert both happened, so that a future
 * change which quietly falls through to the unbounded read cannot pass here
 * vacuously.
 */

/** A FeedBuilder whose window depths are set by the test and whose attempts are counted. */
final class WindowedFeedBuilder extends FeedBuilder
{
    /** @var non-empty-list<int|null> */
    public static array $depths = [null];

    /** @var list<array{floor: ?string, ceiling: ?string}> */
    public static array $attempts = [];

    protected function windowDepths(): array
    {
        return self::$depths;
    }

    protected function groupAggregate(Carbon $now, ?array $cursor, ?string $floor, ?string $ceiling): Collection
    {
        self::$attempts[] = ['floor' => $floor, 'ceiling' => $ceiling];

        return parent::groupAggregate($now, $cursor, $floor, $ceiling);
    }
}

/**
 * Publish a randomized history and then disturb it the way real data is
 * disturbed. Deterministic for a given seed.
 *
 * @return array{user: User, project: Customer}
 */
function windowedFixture(int $seed, int $activities = 160): array
{
    mt_srand($seed);

    $users = collect(range(1, 6))->map(fn ($i) => User::create(['name' => "U{$i}", 'email' => "u{$i}@example.com"]));
    $projects = collect(range(1, 4))->map(fn ($i) => Customer::create(['name' => "P{$i}"]));

    $start = now()->subDays(10)->startOfDay();

    for ($i = 0; $i < $activities; $i++) {
        // Minute-grained over ten days, so same-instant ties are common and
        // day buckets hold several groups each.
        $at = $start->copy()->addMinutes(mt_rand(0, 10 * 24 * 60));

        Storyfeed::activity()
            ->actor($users[mt_rand(0, 5)])
            ->verb(mt_rand(0, 3) === 0 ? 'comment' : 'upload', Delivery::create(['tracking_number' => "d{$i}"]))
            ->for($projects[mt_rand(0, 3)])
            ->publishedAt($at)
            ->publish();
    }

    $all = Activity::query()->orderBy('id')->get();

    // Solos: imported-looking rows with no grouping at all.
    foreach ($all->random(12) as $activity) {
        Grouping::query()->where('activity_id', $activity->getKey())->delete();
    }

    // Uncurated: candidate rows nobody stamped, so `repeat` wins by fallback.
    foreach ($all->random(12) as $activity) {
        Grouping::query()->where('activity_id', $activity->getKey())->update(['winner' => null]);
    }

    // Hidden members: soft-deleted, or published in the future.
    foreach ($all->random(8) as $activity) {
        $activity->delete();
    }
    foreach ($all->random(6) as $activity) {
        $activity->forceFill(['published_at' => now()->addDay()])->save();
    }

    return ['user' => $users[0], 'project' => $projects[0]];
}

/**
 * Walk a feed to exhaustion, returning every page's comparable shape.
 *
 * @param  callable(): FeedBuilder  $feed
 * @return list<array{cursor: ?string, items: list<array<string, mixed>>}>
 */
function walkPages(callable $feed): array
{
    $pages = [];
    $cursor = null;

    do {
        $payload = $feed()->cursor($cursor)->get()->toArray();

        $pages[] = [
            'cursor' => $payload['next_cursor'] ?? null,
            'items' => array_map(fn (array $item) => [
                'kind' => $item['kind'],
                'id' => $item['id'] ?? null,
                'axis' => $item['axis'] ?? null,
                'hash' => $item['hash'] ?? null,
                'count' => $item['count'] ?? null,
                'children' => array_map(fn ($child) => $child['id'], $item['children'] ?? []),
                'distinct' => $item['distinct'] ?? null,
            ], $payload['items']),
        ];

        $cursor = $payload['next_cursor'] ?? null;
    } while ($cursor !== null && count($pages) < 200);

    return $pages;
}

/**
 * @param  callable(FeedBuilder): FeedBuilder  $configure
 * @return array{oracle: list<mixed>, windowed: list<mixed>}
 */
function compareWalks(callable $configure, int $limit, array $depths): array
{
    WindowedFeedBuilder::$depths = [null];
    $oracle = walkPages(fn () => $configure((new WindowedFeedBuilder)->limit($limit)));

    WindowedFeedBuilder::$depths = $depths;
    WindowedFeedBuilder::$attempts = [];
    $windowed = walkPages(fn () => $configure((new WindowedFeedBuilder)->limit($limit)));

    return ['oracle' => $oracle, 'windowed' => $windowed];
}

afterEach(function () {
    WindowedFeedBuilder::$depths = [null];
    WindowedFeedBuilder::$attempts = [];
});

it('pages identically to the unbounded aggregate in every mode', function (string $mode, int $seed) {
    windowedFixture($seed);

    $walks = compareWalks(fn (FeedBuilder $feed) => $feed->{$mode}(), limit: 4, depths: [5, 15, null]);

    expect($walks['windowed'])->toBe($walks['oracle'])
        ->and(count($walks['oracle']))->toBeGreaterThan(5)
        // More than one page carried groups, so the oracle is not trivially solo.
        ->and(collect($walks['oracle'])->filter(fn ($page) => collect($page['items'])->contains('kind', 'group'))->count())->toBeGreaterThan(1);

    $attempts = collect(WindowedFeedBuilder::$attempts);

    // The window engaged (bounded attempts), widened (a second bounded attempt
    // on some page, so both depths were exercised), and under a cursor the
    // ceiling exclusion ran. Without all three this file proves nothing.
    expect($attempts->whereNotNull('floor')->count())->toBeGreaterThan(0)
        ->and($attempts->whereNotNull('floor')->whereNotNull('ceiling')->count())->toBeGreaterThan(0)
        ->and($attempts->count())->toBeGreaterThan(count($walks['windowed']));
})->with(['summary', 'live'])->with([11, 23, 47]);

it('pages identically under scope and verb filters', function () {
    ['user' => $user, 'project' => $project] = windowedFixture(31);

    foreach ([
        'involving' => fn (FeedBuilder $feed) => $feed->summary()->involving($user),
        'target' => fn (FeedBuilder $feed) => $feed->summary()->target($project),
        'verb' => fn (FeedBuilder $feed) => $feed->summary()->only('upload'),
        'callback' => fn (FeedBuilder $feed) => $feed->summary()->query(fn ($q) => $q->where('verb', '!=', 'comment')),
    ] as $name => $configure) {
        $walks = compareWalks($configure, limit: 3, depths: [4, 10, null]);

        expect($walks['windowed'])->toBe($walks['oracle'], "filter: {$name}")
            ->and(collect(WindowedFeedBuilder::$attempts)->whereNotNull('floor')->count())->toBeGreaterThan(0, "filter: {$name}");
    }
});

it('pages identically when the window is deeper than history', function () {
    windowedFixture(5, activities: 30);

    // Depth 1000 on 30 activities: the floor probe finds nothing, so every
    // page must take the unbounded read exactly once — no retries, no recount.
    $walks = compareWalks(fn (FeedBuilder $feed) => $feed->summary(), limit: 4, depths: [1000, null]);

    expect($walks['windowed'])->toBe($walks['oracle'])
        ->and(collect(WindowedFeedBuilder::$attempts)->whereNotNull('floor')->count())->toBe(0)
        ->and(count(WindowedFeedBuilder::$attempts))->toBe(count($walks['windowed']));
});

it('reports true member counts, not the windowed ones', function () {
    // One big group whose older members lie far below any small window's
    // floor, so the windowed COUNT(*) would be wrong without the recount.
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $project = Customer::create(['name' => 'Concur']);
    $day = now()->subDay()->startOfDay();

    foreach (range(1, 12) as $i) {
        Storyfeed::activity()
            ->actor($user)
            ->verb('upload', Delivery::create(['tracking_number' => "big-{$i}"]))
            ->for($project)
            ->publishedAt($day->copy()->addMinutes($i))
            ->publish();
    }

    // A group of one in the same minute as the big group's second-newest
    // member, created later so it sorts directly under the newest. A window
    // two activities deep then holds exactly two groups — one past a page of
    // one — so the window is ACCEPTED with the big group counted at 1.
    Storyfeed::activity()
        ->actor(User::create(['name' => 'Other', 'email' => 'other@example.com']))
        ->verb('upload', Delivery::create(['tracking_number' => 'single']))
        ->for(Customer::create(['name' => 'Elsewhere']))
        ->publishedAt($day->copy()->addMinutes(11))
        ->publish();

    WindowedFeedBuilder::$depths = [2, null];
    WindowedFeedBuilder::$attempts = [];

    $items = (new WindowedFeedBuilder)->summary()->limit(1)->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['kind'])->toBe('group')
        ->and($items[0]['axis'])->toBe('repeat')
        ->and($items[0]['count'])->toBe(12)
        ->and(WindowedFeedBuilder::$attempts)->toHaveCount(1)
        ->and(WindowedFeedBuilder::$attempts[0]['floor'])->not->toBeNull();
});
