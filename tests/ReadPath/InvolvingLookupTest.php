<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\Grouping\Group;
use Storyfeed\Models\Activity;
use Storyfeed\Tests\Fixtures\Models\NestedContainer;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

beforeEach(function () {
    Relation::morphMap(['container' => NestedContainer::class]);
    NestedContainer::install();
});

/** Every page of a feed, followed to the end through its cursors, cursors left out. */
function lookupPages(FeedBuilder $feed): array
{
    $pages = [];
    $cursor = null;
    do {
        $page = (clone $feed)->limit(3)->cursorPaginate(cursor: $cursor)->toArray();
        $cursor = $page['next_cursor'];
        // Cursors differ by design: an involving() cursor records its depth.
        unset($page['sync_token'], $page['next_cursor'], $page['prev_cursor'], $page['next_page_url'], $page['prev_page_url']);
        $pages[] = $page;
    } while ($cursor !== null && count($pages) < 50);

    return $pages;
}

/** The same feed narrowed by the activity's own columns, ordered by the activity. */
function lookupReference(string $mode, mixed $entity, bool $deep): FeedBuilder
{
    $ids = DB::table(SyncParticipants::table())
        ->where('entity_type', $entity->getMorphClass())->where('entity_id', (string) $entity->getKey())
        ->when(! $deep, fn ($query) => $query->where('distance', 0))
        ->pluck('activity_id')->all();

    return Storyfeed::feed()->{$mode}()->query(fn ($query) => $query->whereIn('feed_activities.id', $ids));
}

function lookupHistory(): array
{
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
        match (true) {
            $i % 6 === 5 => $builder->context($other),
            $i === 7 => $builder->context($client),
            default => $builder->context($project),
        };
        $builder->publish();
    }
    Storyfeed::activity()->actor($people[0])->verb('upload')->objects([$quiet, Delivery::create(['tracking_number' => 'pair'])])
        ->context($project)->publishedAt(now()->subMinutes(30))->publish();

    return [$client, $project, $people, $other, $quiet];
}

it('pages an entity from the participants index exactly as from the timeline', function (string $mode, bool $curate) {
    config()->set('storyfeed.grouping.curate', $curate);
    [$client, $project, $people, $other, $quiet] = lookupHistory();

    foreach ([[$client, true], [$client, false], [$project, true], [$people[1], true], [$quiet, true], [$other, false]] as [$entity, $deep]) {
        $involving = lookupPages(Storyfeed::feed()->{$mode}()->involving($entity, deep: $deep));

        expect($involving)->toBe(lookupPages(lookupReference($mode, $entity, $deep)))
            ->and(array_sum(array_map(fn ($page) => count($page['data']), $involving)))->toBeGreaterThan(0);
    }
})->with(['log', 'live'])->with([true, false]);

it('reads a page in feed order from the entity index', function () {
    $project = Customer::create(['name' => 'Concur']);
    $user = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($user)->verb('comment', Delivery::create(['tracking_number' => "d{$i}"]))->to($project)->publish();
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query;
    });
    Storyfeed::feed()->log()->involving($project)->get();
    $read = collect($queries)->first(fn ($query) => str_contains($query->sql, 'order by'));
    $driver = DB::getDriverName();
    $plan = json_encode(DB::select(($driver === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ').$read->sql, $read->bindings), JSON_THROW_ON_ERROR);

    expect($read->sql)->toContain('involved_at')
        ->and($plan)->toContain('feed_participants_entity_published_index');
})->skip(fn () => DB::getDriverName() === 'pgsql', 'PostgreSQL may scan a three-row table; its plans are measured at scale.');

it('moves an activity in an involving() feed when it is rescheduled', function (string $mode) {
    $project = Customer::create(['name' => 'Concur']);
    $user = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $activities = collect(range(1, 3))->map(fn ($i) => Storyfeed::activity()->actor($user)
        ->verb('comment', Delivery::create(['tracking_number' => "d{$i}"]))->to($project)
        ->publishedAt(now()->subHours(10 - $i))->publish());
    $order = fn () => array_column(Storyfeed::feed()->{$mode}()->involving($project)->get()->toArray(), 'id');

    expect($order())->toBe($activities->reverse()->pluck('uid')->values()->all());

    // The oldest moves to the front, and the newest into the future.
    $activities[0]->update(['published_at' => now()->subMinute()]);
    $activities[2]->published_at = now()->addHour();
    $activities[2]->save();

    expect($order())->toBe([$activities[0]->uid, $activities[1]->uid]);

    // A rescheduled activity appears once its time comes.
    $this->travel(2)->hours();
    expect($order())->toBe([$activities[2]->uid, $activities[0]->uid, $activities[1]->uid])
        ->and(Storyfeed::doctor(['participants'])->has('participants.drift'))->toBeFalse();
})->with(['log', 'live']);

it('publishes a scheduled activity into the involving() feed at its time', function () {
    $project = Customer::create(['name' => 'Concur']);
    $user = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $later = Storyfeed::activity()->actor($user)->verb('comment', Delivery::create(['tracking_number' => 'later']))->to($project)
        ->publishedAt(now()->addDay())->publish();

    expect(Storyfeed::feed()->involving($project)->get()->toArray())->toBeEmpty();

    $this->travel(25)->hours();
    expect(array_column(Storyfeed::feed()->log()->involving($project)->get()->toArray(), 'id'))->toBe([$later->uid]);
});

it('reports participant times that drifted from their activity, and repairs them', function () {
    $project = Customer::create(['name' => 'Concur']);
    $user = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $activity = Storyfeed::activity()->actor($user)->verb('comment', Delivery::create(['tracking_number' => 'd']))->to($project)
        ->publishedAt(now()->subHour())->publish();

    // A mass update fires no model event, so nothing re-stamps the rows.
    Activity::query()->whereKey($activity->id)->update(['published_at' => now()->addDay()]);
    $report = Storyfeed::doctor(['participants']);

    expect($report->has('participants.drift'))->toBeTrue()
        ->and($report->withCode('participants.drift')->first()->message)->toContain('storyfeed:participants');

    $this->artisan('storyfeed:participants')->assertSuccessful();

    expect(Storyfeed::doctor(['participants'])->problems())->toBeEmpty()
        ->and(Storyfeed::feed()->involving($project)->get()->toArray())->toBeEmpty();
});

it('reads an entity with no activities as an empty feed', function () {
    $user = User::create(['name' => 'Ann', 'email' => 'ann@example.com']);
    Storyfeed::activity()->actor($user)->verb('comment', Delivery::create(['tracking_number' => 'x']))->publish();
    $nobody = Customer::create(['name' => 'Nobody']);

    expect(Storyfeed::feed()->involving($nobody)->get()->toArray())->toBeEmpty()
        ->and(Storyfeed::feed()->log()->involving($nobody)->get()->toArray())->toBeEmpty()
        ->and(Activity::query()->involving($nobody)->count())->toBe(0);
});
