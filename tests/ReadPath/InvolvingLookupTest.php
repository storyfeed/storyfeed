<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\Grouping\Group;
use Storyfeed\Models\Activity;
use Storyfeed\Support\InvolvingLookup;
use Storyfeed\Tests\Fixtures\Models\NestedContainer;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

beforeEach(function () {
    Relation::morphMap(['container' => NestedContainer::class]);
    NestedContainer::install();
});

afterEach(fn () => InvolvingLookup::useThreshold(null));

/** Every page of a feed, followed to the end through its cursors. */
function lookupPages(FeedBuilder $feed): array
{
    $pages = [];
    $cursor = null;
    do {
        $page = (clone $feed)->limit(3)->cursorPaginate(cursor: $cursor)->toArray();
        unset($page['sync_token']);
        $pages[] = $page;
        $cursor = $page['next_cursor'];
    } while ($cursor !== null && count($pages) < 50);

    return $pages;
}

it('reads the same feed whether an entity is read as quiet or as busy', function (string $mode, bool $curate) {
    config()->set('storyfeed.grouping.curate', $curate);
    Story::verb('upload')->headline(':actor uploaded :object')->grouped(Group::on('repeat')->headline(':actor uploaded :count files'));

    $client = NestedContainer::create(['name' => 'Client']);
    $project = NestedContainer::create(['name' => 'Project', 'parent_type' => 'container', 'parent_id' => $client->id]);
    $people = collect(range(1, 3))->map(fn ($i) => User::create(['name' => "Person {$i}", 'email' => "p{$i}@example.com"]));
    $other = Customer::create(['name' => 'Elsewhere']);
    $quiet = Delivery::create(['tracking_number' => 'quiet']);

    foreach (range(0, 23) as $i) {
        $builder = Storyfeed::activity()->actor($people[$i % 3])
            ->verb($i % 4 === 0 ? 'comment' : 'upload', Delivery::create(['tracking_number' => "f{$i}"]))
            ->publishedAt(now()->subMinutes(100 - $i * ($i % 5 === 0 ? 1 : 3)));
        // Most activities sit in the project; some elsewhere; one at the client.
        match (true) {
            $i % 6 === 5 => $builder->context($other),
            $i === 7 => $builder->context($client),
            default => $builder->context($project),
        };
        $builder->publish();
    }
    Storyfeed::activity()->actor($people[0])->verb('upload')->objects([$quiet, Delivery::create(['tracking_number' => 'pair'])])
        ->context($project)->publishedAt(now()->subMinutes(30))->publish();

    foreach ([[$client, true], [$client, false], [$project, true], [$people[1], true], [$quiet, true], [$other, false]] as [$entity, $deep]) {
        $read = fn () => lookupPages(Storyfeed::feed()->{$mode}()->involving($entity, deep: $deep));
        InvolvingLookup::useThreshold(PHP_INT_MAX >> 1);
        $quietly = $read();
        InvolvingLookup::useThreshold(0);
        $busily = $read();

        expect($busily)->toBe($quietly)
            ->and(array_sum(array_map(fn ($page) => count($page['data']), $quietly)))->toBeGreaterThan(0);
    }
})->with(['log', 'live'])->with([true, false]);

it('decides once per read how to find the entity', function () {
    $project = Customer::create(['name' => 'Concur']);
    $user = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $mine = collect(range(1, 3))->map(fn ($i) => Storyfeed::activity()->actor($user)
        ->verb('comment', Delivery::create(['tracking_number' => "d{$i}"]))->to($project)->publish());
    $ids = $mine->map(fn (Activity $activity) => (string) $activity->uid)->reverse()->values()->all();

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    foreach ([null, 0] as $threshold) {
        InvolvingLookup::useThreshold($threshold);
        foreach (['live', 'log'] as $mode) {
            $queries = [];
            $items = Storyfeed::feed()->{$mode}()->involving($project)->get()->toArray();
            $lookups = array_filter($queries, fn ($sql) => str_contains($sql, 'feed_participants') && ! str_contains($sql, 'feed_activities'));

            expect($lookups)->toHaveCount(1)
                ->and($items)->not->toBeEmpty();
            if ($mode === 'log') {
                expect(array_column($items, 'id'))->toBe($ids);
            }
        }
    }

    // PostgreSQL alone reads a quiet entity by its ids: its planner
    // misjudges (entity_type, entity_id) from independent statistics.
    InvolvingLookup::useThreshold(null);
    $queries = [];
    Storyfeed::feed()->log()->involving($project)->get();
    $read = collect($queries)->first(fn ($sql) => str_contains($sql, 'order by'));
    expect(str_contains($read, 'feed_participants'))->toBe(DB::getDriverName() !== 'pgsql');
});

it('reads an entity with no activities as an empty feed either way', function (int $threshold) {
    InvolvingLookup::useThreshold($threshold);
    $user = User::create(['name' => 'Ann', 'email' => 'ann@example.com']);
    Storyfeed::activity()->actor($user)->verb('comment', Delivery::create(['tracking_number' => 'x']))->publish();
    $nobody = Customer::create(['name' => 'Nobody']);

    expect(Storyfeed::feed()->involving($nobody)->get()->toArray())->toBeEmpty()
        ->and(Storyfeed::feed()->log()->involving($nobody)->get()->toArray())->toBeEmpty()
        ->and(Activity::query()->involving($nobody)->count())->toBe(0);
})->with([0, 100]);
