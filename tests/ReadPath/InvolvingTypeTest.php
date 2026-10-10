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
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\Models\Party;
use Storyfeed\Support\SyncToken;
use Storyfeed\Tests\Fixtures\Models\NestedContainer;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

it('matches a type in every direct role on both builders', function (string $role, bool $deep) {
    $file = Delivery::create(['tracking_number' => 'invoice']);
    $wanted = Storyfeed::activity()->anonymously()->action('inspect')->{$role}($file)->publish();
    Storyfeed::activity()->anonymously()->action('inspect')->{$role}('Other')->publish();
    expect(Activity::query()->involvingType(Delivery::class, deep: $deep)->pluck('uid')->all())->toBe([$wanted->uid])
        ->and(array_column(Storyfeed::feed()->involvingType('delivery', deep: $deep)->log()->get()->items(), 'id'))->toBe([$wanted->uid]);
})->with(['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument', 'location', 'generator'])->with([true, false]);

it('resolves classes instances aliases and lists to stored participant types', function (string $form) {
    $file = Delivery::create(['tracking_number' => 'invoice']);
    $customer = Customer::create(['name' => 'Customer']);
    $first = Storyfeed::activity()->anonymously()->action('inspect', $file)->publish();
    $second = Storyfeed::activity()->anonymously()->action('inspect', $customer)->publish();
    Storyfeed::activity()->anonymously()->action('inspect')->publish();
    $input = match ($form) {
        'class' => Delivery::class,
        'instance' => new Delivery,
        'alias' => 'delivery',
        'list' => [Delivery::class, $customer, 'delivery'],
    };
    $expected = $form === 'list' ? [$first->uid, $second->uid] : [$first->uid];
    expect(Activity::query()->involvingType($input)->orderBy('id')->pluck('uid')->all())->toBe($expected)
        ->and(array_column(Storyfeed::feed()->involvingType($input)->log()->get()->items(), 'id'))->toBe(array_reverse($expected));
})->with(['class', 'instance', 'alias', 'list']);

it('rejects unknown types and empty lists on both builders', function (string $builder) {
    $query = $builder === 'feed' ? Storyfeed::feed() : Activity::query();
    foreach (['unknown-alias', 'App\\Models\\Missing', stdClass::class] as $type) {
        expect(fn () => $query->involvingType($type))->toThrow(InvalidArgumentException::class, "involvingType() cannot resolve type [{$type}]");
    }
    expect(fn () => $query->involvingType([]))->toThrow(InvalidArgumentException::class, 'involvingType() was given an empty list');
})->with(['feed', 'activity']);

it('resolves package participant aliases without the application morph map', function () {
    Relation::enforceMorphMap(['delivery' => Delivery::class], merge: false);
    $activity = Storyfeed::activity()->actor('Operator')->action('inspect')->publish();
    expect(Activity::query()->involvingType('storyfeed.party')->value('uid'))->toBe($activity->uid)
        ->and(Storyfeed::feed()->involvingType(Party::class)->log()->get()->items()[0]['id'])->toBe($activity->uid);
});

function involvingTypeHistory(): array
{
    Relation::morphMap(['container' => NestedContainer::class]);
    NestedContainer::install();
    $parent = Customer::create(['name' => 'Parent']);
    $child = NestedContainer::create(['name' => 'Child', 'parent_type' => 'customer', 'parent_id' => $parent->id]);
    $nested = Storyfeed::activity()->actor('Operator')->action('inspect', $child)->publishedAt(now()->subMinute())->publish();
    $direct = Storyfeed::activity()->actor('Operator')->action('inspect', $parent)->target($parent)->publish();
    $child->update(['parent_type' => null, 'parent_id' => null]);

    return [$parent, $child, $nested, $direct];
}

it('reads recorded ancestor types and excludes ancestor-only matches in direct mode', function (string $mode) {
    [$parent, $child, $nested, $direct] = involvingTypeHistory();
    $token = SyncToken::current();
    expect(Activity::query()->involvingType(Customer::class)->orderBy('id')->pluck('uid')->all())->toBe([$nested->uid, $direct->uid])
        ->and(Activity::query()->involvingType(Customer::class, deep: false)->pluck('uid')->all())->toBe([$direct->uid])
        ->and(array_column(Storyfeed::feed()->{$mode}()->involvingType('customer')->get()->items(), 'id'))->toBe([$direct->uid, $nested->uid])
        ->and(array_column(Storyfeed::feed()->{$mode}()->involvingType($parent, deep: false)->get()->items(), 'id'))->toBe([$direct->uid])
        ->and(SyncToken::current())->toBe($token);
})->with(['log', 'live']);

it('ANDs participation types with identity verbs role types and other type calls', function () {
    [$parent, $child, $nested] = involvingTypeHistory();
    Storyfeed::activity()->actor('Operator')->action('ignore', $child)->target($parent)->publish();
    foreach ([Storyfeed::feed()->log(), Activity::query()] as $builder) {
        $builder->involvingType(Customer::class)->involvingType(NestedContainer::class)
            ->involving($parent)->verb('inspect')->objectType(NestedContainer::class);
        if ($builder instanceof FeedBuilder) {
            $builder->query(fn (ActivityBuilder $q) => $q->where('verb', 'inspect')->orWhere('verb', 'ignore'));
        }
        expect($builder instanceof FeedBuilder ? array_column($builder->get()->items(), 'id') : $builder->pluck('uid')->all())->toBe([$nested->uid]);
    }
});

it('recounts Live group members and distinct roles within participant types', function (bool $curate) {
    config(['storyfeed.grouping.curate' => $curate, 'storyfeed.grouping.children_limit' => 2]);
    Story::verb('inspect')->grouped(Group::repeat()->headline(':count things'));
    $customer = Customer::create(['name' => 'Customer']);
    foreach (range(1, 4) as $n) {
        $file = Delivery::create(['tracking_number' => 'invoice'.$n]);
        Storyfeed::activity()->actor('Operator')->action('inspect', $file)->origin($n === 4 ? 'Excluded' : $customer)->publish();
    }
    expect(Storyfeed::feed()->live()->get()->items()[0]['count'])->toBe(4);
    $node = Storyfeed::feed()->live()->involvingType(Customer::class)->get()->items()[0];
    expect($node['kind'])->toBe('group')->and($node['count'])->toBe(3)
        ->and($node['children'])->toHaveCount(2)->and($node['distinct']['objects'])->toBe(3)
        ->and(Storyfeed::feed()->live()->involvingType('courier')->get()->items())->toBeEmpty();
})->with([true, false]);

it('pages both modes and binds participation-type depth independently of identity depth', function (string $mode, bool $deep) {
    [$parent] = involvingTypeHistory();
    Storyfeed::activity()->actor('Operator')->action('another', $parent)->publish();
    $builder = Storyfeed::feed()->{$mode}()->involvingType(Customer::class, deep: $deep)->involving($parent);
    $expected = $builder->get()->items();
    $page = (clone $builder)->limit(1)->get();
    $cursor = $page->nextCursor();
    expect($cursor)->not->toBeNull()
        ->and(fn () => Storyfeed::feed()->{$mode}()->involvingType(Customer::class, deep: ! $deep)->involving($parent)->cursor($cursor)->get())
        ->toThrow(InvalidArgumentException::class, 'different involvingType depth')
        ->and(fn () => (clone $builder)->involving($parent, deep: false)->cursor($cursor)->get())
        ->toThrow(InvalidArgumentException::class, 'different involving depth');
    $seen = $page->items();
    do {
        $page = (clone $builder)->limit(1)->cursor($cursor)->get();
        array_push($seen, ...$page->items());
        $cursor = $page->nextCursor();
        expect(count($seen))->toBeLessThanOrEqual(count($expected));
    } while ($cursor !== null);
    expect($seen)->toBe($expected);
})->with(['log', 'live'])->with([true, false]);

it('round-trips direct type depth through Laravel cursor pagination', function (string $mode) {
    [$parent] = involvingTypeHistory();
    Storyfeed::activity()->anonymously()->action('another', $parent)->publish();
    $page = Storyfeed::feed()->{$mode}()->involvingType('customer', deep: false)->cursorPaginate(1);
    request()->merge(['cursor' => $page->nextCursor()->encode()]);
    expect(Storyfeed::feed()->{$mode}()->involvingType(Customer::class, deep: false)->cursorPaginate(1)->items())->toHaveCount(1)
        ->and(fn () => Storyfeed::feed()->{$mode}()->involvingType('customer')->cursorPaginate(1))
        ->toThrow(InvalidArgumentException::class, 'different involvingType depth');
})->with(['log', 'live']);

it('keeps repeated participation-type depths in the cursor', function (string $mode) {
    [$parent] = involvingTypeHistory();
    Storyfeed::activity()->actor('Operator')->action('another', $parent)->publish();
    $builder = Storyfeed::feed()->{$mode}()->involvingType(Customer::class, deep: false)->involvingType(Party::class);
    $cursor = (clone $builder)->limit(1)->get()->nextCursor();
    expect($cursor)->not->toBeNull()
        ->and((clone $builder)->cursor($cursor)->get()->items())->toHaveCount(1)
        ->and(fn () => Storyfeed::feed()->{$mode}()->involvingType(Customer::class, deep: false)
            ->involvingType(Party::class, deep: false)->cursor($cursor)->get())
        ->toThrow(InvalidArgumentException::class, 'different involvingType depth');
})->with(['log', 'live']);

class InvolvingTypeSubjectFeed extends Feed
{
    public function __construct(protected Model $subject) {}

    protected function scope(FeedBuilder $feed): void
    {
        $feed->involvingType($this->subject, deep: false);
    }
}

it('shares the involving scope lock with identity and type filters', function () {
    $subject = new Delivery;
    $feed = InvolvingTypeSubjectFeed::make($subject);
    expect($feed->boundRoles())->toBe(['involving'])
        ->and(fn () => $feed->involving($subject))->toThrow(FeedMisconfigured::class, 'cannot be rebound')
        ->and(fn () => $feed->involvingType(Customer::class))->toThrow(FeedMisconfigured::class, 'cannot be rebound')
        ->and(fn () => Storyfeed::feed()->involving($subject)->lockScope('involving', 'IdentityFeed')->involvingType(Delivery::class))
        ->toThrow(FeedMisconfigured::class, 'cannot be rebound');
});

it('uses the participants entity index for single and list types at both depths', function () {
    $rows = [];
    foreach (range(1, 2000) as $id) {
        $rows[] = ['activity_id' => $id, 'role' => 'object', 'entity_type' => $id === 1 ? 'delivery' : 'imported_'.$id,
            'entity_id' => (string) $id, 'distance' => $id % 2, 'published_at' => now()];
    }
    foreach (array_chunk($rows, 250) as $chunk) {
        DB::table(SyncParticipants::table())->insert($chunk);
    }
    $driver = DB::getDriverName();
    DB::statement(in_array($driver, ['mysql', 'mariadb']) ? 'ANALYZE TABLE feed_participants' : 'ANALYZE feed_participants');
    $plans = [];
    foreach ([true, false] as $deep) {
        foreach ([Delivery::class, [Delivery::class, Customer::class]] as $types) {
            $query = Activity::query()->involvingType($types, deep: $deep);
            $plan = json_encode(DB::select(($driver === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ').$query->toSql(), $query->getBindings()), JSON_THROW_ON_ERROR);
            expect($plan)->toContain('feed_participants_entity_published_index');
            $plans[] = ['deep' => $deep, 'types' => $types, 'plan' => json_decode($plan, true, flags: JSON_THROW_ON_ERROR)];
        }
    }
    if ($path = getenv('STORYFEED_INVOLVING_TYPE_REPORT')) {
        file_put_contents($path, json_encode(['driver' => $driver, 'plans' => $plans], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
    }
});
