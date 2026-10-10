<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Exceptions\FeedMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Feed;
use Storyfeed\FeedBuilder;
use Storyfeed\Grouping\Group;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\Support\SyncToken;
use Storyfeed\Tests\Fixtures\Models\NestedContainer;

beforeEach(function () {
    Relation::morphMap(['container' => NestedContainer::class]);
    NestedContainer::install();
});

function directContainer(string $name, ?Model $parent = null): NestedContainer
{
    return NestedContainer::create(['name' => $name, 'parent_type' => $parent?->getMorphClass(), 'parent_id' => $parent?->getKey()]);
}

function directHistory(): array
{
    $client = directContainer('Client');
    $project = directContainer('Project', $client);
    $list = directContainer('List', $project);
    $task = directContainer('Task', $list);
    $activities = [];
    foreach ([$client, $project, $list, $task, $client] as $i => $entity) {
        $activities[] = Storyfeed::activity()->anonymously()->action('action_'.$i, $entity)
            ->publishedAt(now()->subMinutes(10 - $i))->publish();
    }

    return [$client, $project, $list, $task, $activities];
}

it('reads recorded descendants by default and pages only direct roles when requested', function (string $mode, bool $curate) {
    config()->set('storyfeed.grouping.curate', $curate);
    [$client, $project, $list, $task, $activities] = directHistory();
    $before = DB::table(SyncParticipants::table())->orderBy('id')->get()->toJson();
    $token = SyncToken::bump();

    foreach ([$client, $project, $list, $task] as $level => $entity) {
        $deep = Storyfeed::feed()->{$mode}()->involving($entity)->get();
        expect($deep->items())->toHaveCount($level === 0 ? 5 : 4 - $level)
            ->and(Storyfeed::feed()->{$mode}()->involving($entity, deep: true)->get()->toArray())->toBe($deep->toArray());
    }

    foreach (['default', 'false', 'alias'] as $spelling) {
        $builder = Storyfeed::feed()->{$mode}();
        match ($spelling) {
            'default' => $builder->involving($client),
            'false' => $builder->involving($client, deep: false),
            'alias' => $builder->involvingDirectly($client),
        };
        $expected = $spelling === 'default' ? array_reverse($activities) : [$activities[4], $activities[0]];
        $ids = array_map(fn (Activity $activity) => (string) $activity->uid, $expected);
        expect(array_column($builder->get()->items(), 'id'))->toBe($ids);

        $seen = [];
        $cursor = null;
        do {
            $page = (clone $builder)->limit(1)->cursor($cursor)->get();
            expect($page->toArray()['sync_token'])->toBe($token);
            array_push($seen, ...array_column($page->items(), 'id'));
            $cursor = $page->nextCursor();
            expect(count($seen))->toBeLessThanOrEqual(count($ids));
        } while ($cursor !== null);
        expect($seen)->toBe($ids);
    }
    expect(DB::table(SyncParticipants::table())->orderBy('id')->get()->toJson())->toBe($before);
})->with(['log', 'live'])->with([true, false]);

it('rejects depth changes in either direction and accepts either direct spelling', function (string $mode, bool $deep) {
    [$client] = directHistory();
    $page = Storyfeed::feed()->{$mode}()->involving($client, deep: $deep)->limit(1)->get();
    expect($page->nextCursor())->not->toBeNull();
    expect(fn () => Storyfeed::feed()->{$mode}()->involving($client, deep: ! $deep)->cursor($page->nextCursor())->get())
        ->toThrow(InvalidArgumentException::class, 'different involving depth');
    if (! $deep) {
        expect(Storyfeed::feed()->{$mode}()->involvingDirectly($client)->cursor($page->nextCursor())->get()->items())->toHaveCount(1);
    }
})->with(['log', 'live'])->with([true, false]);

it('round-trips direct depth through Laravel cursor pagination', function (string $mode) {
    [$client] = directHistory();
    $page = Storyfeed::feed()->{$mode}()->involvingDirectly($client)->cursorPaginate(1);
    expect($page->nextCursor())->not->toBeNull();
    request()->merge(['cursor' => $page->nextCursor()->encode()]);
    $next = Storyfeed::feed()->{$mode}()->involving($client, deep: false)->cursorPaginate(1);
    expect($next->items())->toHaveCount(1)->and($next->nextCursor())->toBeNull();
    expect(fn () => Storyfeed::feed()->{$mode}()->involving($client)->cursorPaginate(1))
        ->toThrow(InvalidArgumentException::class, 'different involving depth');
})->with(['log', 'live']);

it('pages grouped nodes with counts and children restricted to direct roles', function (bool $curate) {
    config()->set('storyfeed.grouping.curate', $curate);
    $client = directContainer('Client');
    $task = directContainer('Task', $client);
    $actor = directContainer('Editor');
    foreach (['revise_alpha', 'revise_beta', 'revise_gamma'] as $i => $verb) {
        Story::verb($verb)->headline(':actor revised :object')->grouped(Group::repeat()->headline(':actor revised :count things'));
        foreach ([$client, $client, $task, $task] as $object) {
            Storyfeed::activity()->actor($actor)->action($verb, $object)->publishedAt(now()->subMinutes(10 - $i))->publish();
        }
    }
    foreach ([true, false] as $deep) {
        $builder = Storyfeed::feed()->live()->involving($client, deep: $deep);
        $expected = $builder->get()->items();
        $seen = [];
        $cursor = null;
        do {
            $page = (clone $builder)->limit(1)->cursor($cursor)->get();
            array_push($seen, ...$page->items());
            $cursor = $page->nextCursor();
            expect(count($seen))->toBeLessThanOrEqual(count($expected));
        } while ($cursor !== null);
        expect($seen)->toBe($expected)
            ->and(array_sum(array_column($seen, 'count')))->toBe($deep ? 12 : 6);
        if (! $deep) {
            foreach ($seen as $node) {
                expect($node['count'])->toBe(2)
                    ->and(array_column($node['children'], 'object'))->each->toMatchArray(['id' => (string) $client->id]);
            }
        }
    }
})->with([true, false]);

it('keeps all seven direct roles including a role that also lies on a parent chain', function (string $role) {
    $client = directContainer('Client');
    $task = directContainer('Task', $client);
    $pending = Storyfeed::activity()->anonymously()->action('revise', $task);
    $pending->{$role}($client);
    $activity = $pending->publish();
    expect(DB::table(SyncParticipants::table())->where('activity_id', $activity->id)->where('entity_id', $client->id)->value('distance'))->toBe(0)
        ->and(Activity::query()->involving($client, deep: false)->count())->toBe(1)
        ->and(Activity::query()->involvingDirectly($client)->count())->toBe(1)
        ->and(Storyfeed::feed()->involvingDirectly($client)->get()->items())->toHaveCount(1);
})->with(['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument', 'location', 'generator']);

it('keeps parentless entity reads identical in both modes', function (string $mode) {
    $entity = directContainer('Standalone');
    Storyfeed::activity()->anonymously()->action('create', $entity)->publish();
    $deep = Storyfeed::feed()->{$mode}()->involving($entity)->get()->toArray();
    expect(Storyfeed::feed()->{$mode}()->involving($entity, deep: false)->get()->toArray())->toBe($deep)
        ->and(Storyfeed::feed()->{$mode}()->involvingDirectly($entity)->get()->toArray())->toBe($deep);
})->with(['log', 'live']);

it('recounts grouped members and distinct roles within direct participation', function (bool $curate) {
    config()->set('storyfeed.grouping.curate', $curate);
    Story::verb('revise')->headline(':actor revised :object')->grouped(Group::repeat()->headline(':actor revised :count things'));
    $client = directContainer('Client');
    $task = directContainer('Task', $client);
    $actor = directContainer('Editor');
    foreach ([$client, $client, $task, $task] as $object) {
        Storyfeed::activity()->actor($actor)->action('revise', $object)->publish();
    }
    $all = Storyfeed::feed()->involving($client)->get()->items();
    expect(array_sum(array_column($all, 'count')))->toBe(4);
    foreach ([Storyfeed::feed()->involving($client, deep: false), Storyfeed::feed()->involvingDirectly($client)] as $feed) {
        $items = $feed->get()->items();
        expect($items)->toHaveCount(1)
            ->and($items[0]['kind'])->toBe('group')
            ->and($items[0]['count'])->toBe(2)
            ->and($items[0]['distinct']['objects'])->toBe(1)
            ->and(array_column($items[0]['children'], 'object'))->each->toMatchArray(['id' => (string) $client->id]);
    }
    expect(Activity::query()->involving($client)->count())->toBe(4)
        ->and(Activity::query()->involving($client, deep: false)->count())->toBe(2);
})->with([true, false]);

it('filters composite members and imported solos in both modes', function (string $mode) {
    $client = directContainer('Client');
    $task = directContainer('Task', $client);
    Storyfeed::activity()->anonymously()->action('create')->objects([$client, $task])->publish();
    $direct = Storyfeed::feed()->{$mode}()->involvingDirectly($client)->get()->items();
    expect($direct)->toHaveCount(1)
        ->and($direct[0]['object']['id'])->toBe((string) $client->id);
    Grouping::query()->delete();
    expect(Storyfeed::feed()->{$mode}()->involving($client)->get()->items())->toHaveCount(2)
        ->and(Storyfeed::feed()->{$mode}()->involvingDirectly($client)->get()->items())->toHaveCount(1);
})->with(['log', 'live']);

it('resolves party names and never widens an unresolved direct filter', function () {
    $party = Storyfeed::party('Workspace');
    $task = directContainer('Task', $party);
    Storyfeed::activity()->anonymously()->action('create', $task)->publish();
    Storyfeed::activity()->by($party)->action('announce')->publish();
    expect(Storyfeed::feed()->involving('Workspace')->get()->items())->toHaveCount(2)
        ->and(Storyfeed::feed()->involving('Workspace', deep: false)->get()->items())->toHaveCount(1)
        ->and(Storyfeed::feed()->involvingDirectly('Workspace')->get()->items())->toHaveCount(1)
        ->and(Storyfeed::feed()->involvingDirectly('Unknown')->get()->items())->toBeEmpty();
});

class DirectContainerFeed extends Feed
{
    public function __construct(protected NestedContainer $container) {}

    protected function scope(FeedBuilder $feed): void
    {
        $feed->involvingDirectly($this->container);
    }
}

it('carries direct scope through class and named feeds and shares the existing role lock', function () {
    [$client, $project] = directHistory();
    $feed = DirectContainerFeed::make($client);
    expect($feed->boundRoles())->toBe(['involving'])
        ->and($feed->get()->items())->toHaveCount(2);
    expect(fn () => $feed->involving($project))->toThrow(FeedMisconfigured::class, 'cannot be rebound');
    expect(fn () => $feed->involvingDirectly($project))->toThrow(FeedMisconfigured::class, 'cannot be rebound');
    $deep = Storyfeed::feed()->involving($client)->lockScope('involving', 'DeepFeed');
    expect(fn () => $deep->involvingDirectly($client))->toThrow(FeedMisconfigured::class, 'cannot be rebound');
    Storyfeed::feeds(['direct-client' => fn (FeedBuilder $feed) => $feed->involvingDirectly($client)]);
    expect(Storyfeed::feed('direct-client')->get()->items())->toHaveCount(2)
        ->and(Storyfeed::feed()->involvingDirectly($client)->involving($project)->get()->items())->toHaveCount(3);
});

it('reads a quiet entity from the entity index in both participant lookup modes', function () {
    $rows = [];
    foreach (range(1, 2000) as $id) {
        $rows[] = ['activity_id' => $id, 'role' => $id % 2 ? 'object' : 'ancestor', 'entity_type' => 'container',
            'entity_id' => (string) $id, 'distance' => $id % 2 ? 0 : 2, 'published_at' => now()];
    }
    foreach (array_chunk($rows, 250) as $chunk) {
        DB::table(SyncParticipants::table())->insert($chunk);
    }
    $driver = DB::getDriverName();
    if ($driver === 'pgsql') {
        DB::statement('vacuum analyze feed_participants');
    } elseif (in_array($driver, ['mysql', 'mariadb'])) {
        DB::statement('analyze table feed_participants');
    }
    $entity = new NestedContainer(['id' => 1]);
    // A quiet entity's activity ids are read up front: that lookup is the
    // query the entity index serves.
    $lookups = [];
    DB::listen(function ($query) use (&$lookups) {
        if (str_contains($query->sql, 'feed_participants') && ! str_contains($query->sql, 'feed_activities')) {
            $lookups[] = $query;
        }
    });
    foreach ([true, false] as $deep) {
        $lookups = [];
        Activity::query()->involving($entity, deep: $deep);
        expect($lookups)->toHaveCount(1);
        $plan = json_encode(DB::select(($driver === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ').$lookups[0]->sql, $lookups[0]->bindings), JSON_THROW_ON_ERROR);
        expect($plan)->toContain('feed_participants_entity_published_index');
    }
});
