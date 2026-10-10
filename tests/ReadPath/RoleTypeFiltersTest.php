<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Storyfeed\Exceptions\FeedMisconfigured;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Feed;
use Storyfeed\FeedBuilder;
use Storyfeed\Grouping\Axis;
use Storyfeed\Grouping\Group;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Party;
use Storyfeed\Support\SyncToken;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

it('filters every role by stored morph class on both builders', function (string $role, string $form) {
    $first = Delivery::create(['tracking_number' => 'first']);
    $second = Delivery::create(['tracking_number' => 'second']);
    $other = Customer::create(['name' => 'Other']);
    $activities = [];
    foreach ([$first, $second, $other] as $model) {
        $activities[] = Storyfeed::activity()->anonymously()->action('inspect')->{$role}($model)->publish();
    }
    Storyfeed::activity()->anonymously()->action('inspect')->publish();

    $input = match ($form) {
        'class' => Delivery::class,
        'instance' => new Delivery,
        'alias' => 'delivery',
        'list' => [Delivery::class, $other, 'delivery'],
    };
    $expected = array_slice($activities, 0, $form === 'list' ? 3 : 2);
    $method = $role.'Type';
    expect(Activity::query()->{$method}($input)->orderBy('id')->pluck('uid')->all())
        ->toBe(array_column($expected, 'uid'))
        ->and(array_column(Storyfeed::feed()->{$method}($input)->log()->get()->toArray(), 'id'))
        ->toBe(array_reverse(array_column($expected, 'uid')))
        ->and($activities[0]->getAttribute($role.'_type'))->toBe('delivery');
})->with(['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument', 'location', 'generator'])
    ->with(['class', 'instance', 'alias', 'list']);

it('rejects unknown types and empty lists before querying', function (string $role, string $builder) {
    $query = $builder === 'feed' ? Storyfeed::feed() : Activity::query();
    $method = $role.'Type';
    foreach (['missing-alias', 'App\\Models\\Missing', stdClass::class, Model::class] as $input) {
        expect(fn () => $query->{$method}($input))->toThrow(InvalidArgumentException::class, "{$method}() cannot resolve type [{$input}]");
    }
    expect(fn () => $query->{$method}([]))->toThrow(InvalidArgumentException::class, "{$method}() was given an empty list")
        ->and(fn () => $query->{$method}([Delivery::class, 'missing-alias']))->toThrow(InvalidArgumentException::class, 'missing-alias');
})->with(['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument', 'location', 'generator'])->with(['feed', 'activity']);

it('resolves package aliases independently of an enforced application morph map', function () {
    Relation::enforceMorphMap(['delivery' => Delivery::class], merge: false);
    $party = Storyfeed::party('Stripe');
    $activity = Storyfeed::activity()->actor($party)->action('inspect', $party)->publish();
    expect(Activity::query()->objectType('storyfeed.party')->value('uid'))->toBe($activity->uid)
        ->and(Storyfeed::feed()->objectType(Party::class)->log()->get()->toArray()[0]['id'])->toBe($activity->uid)
        ->and(Activity::query()->resultType(FeedTombstone::MORPH_ALIAS)->getBindings())->toContain(FeedTombstone::MORPH_ALIAS)
        ->and(Activity::query()->resultType(FeedTombstone::class)->getBindings())->toContain(FeedTombstone::MORPH_ALIAS);
});

it('supports unmapped model classes when the application permits them', function () {
    Relation::morphMap([], merge: false);
    Relation::requireMorphMap(false);
    $model = Delivery::create(['tracking_number' => 'unmapped']);
    $activity = Storyfeed::activity()->anonymously()->action('inspect', $model)->publish();
    expect($activity->object_type)->toBe(Delivery::class)
        ->and(Activity::query()->objectType(Delivery::class)->value('uid'))->toBe($activity->uid)
        ->and(Storyfeed::feed()->objectType($model)->log()->get()->toArray()[0]['id'])->toBe($activity->uid);
});

it('ANDs types with record filters in either order without changing party-name semantics', function (bool $typeFirst) {
    $party = Storyfeed::party('Stripe');
    $file = Delivery::create(['tracking_number' => 'invoice']);
    $wanted = Storyfeed::activity()->actor($party)->action('inspect', $party)->publish();
    Storyfeed::activity()->actor($party)->action('inspect', $file)->publish();
    foreach ([Storyfeed::feed()->log(), Activity::query()] as $builder) {
        $record = $builder instanceof FeedBuilder ? 'Stripe' : $party;
        $query = $typeFirst ? $builder->objectType(Party::class)->object($record) : $builder->object($record)->objectType(Party::class);
        expect($query instanceof FeedBuilder ? array_column($query->get()->toArray(), 'id') : $query->pluck('uid')->all())->toBe([$wanted->uid]);
        $query->objectType(Delivery::class);
        expect($query instanceof FeedBuilder ? $query->get()->toArray() : $query->get()->all())->toBeEmpty();
    }
})->with([true, false]);

it('ANDs repeated types with participation verbs other roles and callback ORs', function () {
    $customer = Customer::create(['name' => 'Mine']);
    $other = Customer::create(['name' => 'Other']);
    $file = Delivery::create(['tracking_number' => 'invoice']);
    $wanted = Storyfeed::activity()->actor('Operator')->action('inspect', $file)->target($customer)->publish();
    Storyfeed::activity()->actor('Operator')->action('ignore', $file)->target($customer)->publish();
    Storyfeed::activity()->actor('Operator')->action('inspect', $other)->target($customer)->publish();
    Storyfeed::activity()->actor('Operator')->action('inspect', $file)->target($other)->publish();
    foreach ([Storyfeed::feed()->log(), Activity::query()] as $builder) {
        $builder->objectType([Delivery::class, Customer::class])->objectType('delivery')
            ->actorType(Party::class)->targetType(Customer::class)->target($customer)->involving($customer)->verb('inspect');
        if ($builder instanceof FeedBuilder) {
            $builder->query(fn (ActivityBuilder $query) => $query->where('verb', 'inspect')->orWhere('verb', 'ignore'));
        }
        expect($builder instanceof FeedBuilder ? array_column($builder->get()->toArray(), 'id') : $builder->pluck('uid')->all())->toBe([$wanted->uid]);
    }
});

class RoleTypeSubjectFeed extends Feed
{
    public function __construct(protected Model $subject, protected bool $typeOnly) {}

    protected function scope(FeedBuilder $feed): void
    {
        $this->typeOnly ? $feed->objectType($this->subject) : $feed->object($this->subject);
    }
}

it('shares Feed-class scope locks between type and record filters', function (bool $typeOnly) {
    $file = Delivery::create(['tracking_number' => 'invoice']);
    $feed = RoleTypeSubjectFeed::make($file, $typeOnly);
    expect($feed->boundRoles())->toBe(['object'])
        ->and(fn () => $feed->objectType(Delivery::class))->toThrow(FeedMisconfigured::class, 'cannot be rebound')
        ->and(fn () => $feed->object($file))->toThrow(FeedMisconfigured::class, 'cannot be rebound');
    $feed->targetType(Customer::class);
    expect($feed->boundRoles())->toBe(['object', 'target']);
})->with([true, false]);

it('recounts mixed-type Live groups and pages only matching children', function (bool $curate) {
    config(['storyfeed.grouping.curate' => $curate, 'storyfeed.grouping.children_limit' => 2]);
    Storyfeed::axes([Axis::make('repeat')->key('v:d')->fallback()], merge: false);
    $customer = Customer::create(['name' => 'Excluded']);
    $expectedIds = [];
    foreach (['inspect_alpha', 'inspect_beta', 'inspect_gamma'] as $verb) {
        Story::verb($verb)->grouped(Group::repeat()->headline(':count things'));
        foreach (range(1, 3) as $n) {
            $file = Delivery::create(['tracking_number' => $verb.$n]);
            $expectedIds[] = Storyfeed::activity()->actor('Operator')->action($verb, $file)->origin($file)->publish()->uid;
        }
        Storyfeed::activity()->actor('Excluded')->action($verb, $customer)->origin($customer)->publish();
    }
    $token = SyncToken::current();
    $unfiltered = Storyfeed::feed()->live()->get()->toArray();
    expect($unfiltered)->toHaveCount(3)->and(array_column($unfiltered, 'count'))->toBe([4, 4, 4]);
    $builder = Storyfeed::feed()->objectType(Delivery::class)->originType('delivery')->live();
    $expected = $builder->get()->toArray();
    $seen = [];
    $cursor = null;
    do {
        $page = (clone $builder)->limit(1)->cursorPaginate(cursor: $cursor);
        array_push($seen, ...$page->toArray()['data']);
        $cursor = $page->nextCursor();
        expect(count($seen))->toBeLessThanOrEqual(3)->and($page->toArray()['sync_token'])->toBe($token);
    } while ($cursor !== null);
    expect($seen)->toBe($expected)->toHaveCount(3);
    foreach ($seen as $node) {
        expect($node['kind'])->toBe('group')->and($node['count'])->toBe(3)
            ->and($node['children'])->toHaveCount(2)->and($node['distinct']['objects'])->toBe(3)
            ->and($node['distinct']['actors'])->toBe(1)->and(array_column($node['children'], 'id'))->each->toBeIn($expectedIds);
    }
    expect(SyncToken::current())->toBe($token);
})->with([true, false]);

it('pages filtered log and live solos including imported rows and composites', function (string $mode, bool $imported) {
    $file = Delivery::create(['tracking_number' => 'invoice']);
    $other = Customer::create(['name' => 'Excluded']);
    Storyfeed::activity()->anonymously()->action('inspect')->objects([$file, $other])->publish();
    foreach (range(1, 3) as $n) {
        Storyfeed::activity()->anonymously()->action('inspect_'.$n, $file)->publish();
        Storyfeed::activity()->anonymously()->action('inspect_'.$n, $other)->publish();
    }
    if ($imported) {
        Grouping::query()->delete();
    }
    $builder = Storyfeed::feed()->objectType('delivery')->{$mode}();
    $expected = $builder->get()->toArray();
    $seen = [];
    $cursor = null;
    do {
        $page = (clone $builder)->limit(1)->cursorPaginate(cursor: $cursor);
        array_push($seen, ...$page->toArray()['data']);
        $cursor = $page->nextCursor();
        expect(count($seen))->toBeLessThanOrEqual(4);
    } while ($cursor !== null);
    expect($seen)->toBe($expected)->toHaveCount(4)
        ->and(array_column($seen, 'object'))->each->toMatchArray(['type' => 'delivery']);
})->with(['log', 'live'])->with([true, false]);

it('uses existing activity indexes for every type-only predicate', function () {
    $rows = [];
    foreach (range(1, 2000) as $id) {
        $row = ['uid' => (string) Str::ulid(), 'verb' => 'inspect', 'published_at' => now()];
        foreach (['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'] as $role) {
            $row[$role.'_type'] = $id === 1 ? 'delivery' : 'imported_'.$id;
            $row[$role.'_id'] = (string) $id;
        }
        $rows[] = $row;
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('feed_activities')->insert($chunk);
    }
    $driver = DB::getDriverName();
    DB::statement(match ($driver) {
        'pgsql' => 'ANALYZE feed_activities',
        'mysql', 'mariadb' => 'ANALYZE TABLE feed_activities',
        default => 'ANALYZE feed_activities',
    });
    $plans = [];
    foreach (['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'] as $role) {
        $query = Activity::query()->{$role.'Type'}(Delivery::class)->published()->select('id')->orderByDesc('published_at')->orderByDesc('id');
        $plan = json_encode(DB::select(($driver === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ').$query->toSql(), $query->getBindings()), JSON_THROW_ON_ERROR);
        expect($plan)->toMatch('/feed_activities_'.$role.'_(type_'.$role.'_id|published)_index/');
        $plans[$role] = json_decode($plan, true, flags: JSON_THROW_ON_ERROR);
    }
    if ($path = getenv('STORYFEED_ROLE_TYPES_REPORT')) {
        file_put_contents($path, json_encode(['driver' => $driver, 'plans' => $plans], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    }
});
