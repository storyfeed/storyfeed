<?php

use Illuminate\Database\Eloquent\Model;
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
use Storyfeed\Models\Grouping;
use Storyfeed\Models\Party;
use Storyfeed\Support\ActivityRoles;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;

it('filters each identity by morph alias on both builders', function (string $role) {
    $model = Delivery::create(['tracking_number' => 'wanted']);
    $other = Delivery::create(['tracking_number' => 'other']);
    $differentType = Customer::create(['name' => 'Same key, different type']);
    $wanted = Storyfeed::activity()->anonymously()->action('inspect')->{$role}($model)->publish();
    foreach ([$other, $differentType, null] as $excluded) {
        Storyfeed::activity()->anonymously()->action('inspect')->{$role}($excluded)->publish();
    }

    expect($wanted->getAttribute($role.'_type'))->toBe('delivery')
        ->and(Activity::query()->{$role}($model)->pluck('uid')->all())->toBe([$wanted->uid])
        ->and(array_column(Storyfeed::feed()->{$role}($model)->log()->get()->toArray(), 'id'))->toBe([$wanted->uid]);
})->with(ActivityRoles::STORED);

it('looks up party names without creating rows and keeps unresolved reads empty', function (string $role, string $mode) {
    $party = Storyfeed::party('Connected App');
    $wanted = Storyfeed::activity()->anonymously()->action('inspect')->{$role}($party)->publish();
    Storyfeed::activity()->anonymously()->action('inspect')->{$role}('Other App')->publish();
    $count = Party::query()->count();

    expect(array_column(Storyfeed::feed()->{$role}('Connected App')->{$mode}()->get()->toArray(), 'id'))->toBe([$wanted->uid])
        ->and(Activity::query()->{$role}($party)->pluck('uid')->all())->toBe([$wanted->uid])
        ->and(Storyfeed::feed()->{$role}('Never Used')->{$mode}()->get()->toArray())->toBeEmpty()
        ->and(Storyfeed::feed()->{$role}('Never Used')->{$role}($party)->{$mode}()->get()->toArray())->toBeEmpty()
        ->and(Party::query()->count())->toBe($count);
})->with(ActivityRoles::STORED)->with(['log', 'live']);

it('ANDs new identities with types other roles participation verbs and callback ORs', function (string $role, bool $typeFirst) {
    $subject = Customer::create(['name' => 'Mine']);
    $other = Customer::create(['name' => 'Other']);
    $party = Storyfeed::party('Connected App');
    $file = Delivery::create(['tracking_number' => 'invoice']);
    $wanted = Storyfeed::activity()->actor('Operator')->action('inspect', $file)->target($subject)->{$role}($party)->publish();
    Storyfeed::activity()->actor('Operator')->action('ignore', $file)->target($subject)->{$role}($party)->publish();
    Storyfeed::activity()->actor('Operator')->action('inspect', $file)->target($other)->{$role}($party)->publish();
    Storyfeed::activity()->actor('Operator')->action('inspect', $file)->target($subject)->{$role}('Other App')->publish();
    foreach ([Storyfeed::feed()->log(), Activity::query()] as $builder) {
        $record = $builder instanceof FeedBuilder ? 'Connected App' : $party;
        $type = $role.'Type';
        $typeFirst ? $builder->{$type}(Party::class)->{$role}($record) : $builder->{$role}($record)->{$type}(Party::class);
        $builder->object($file)->target($subject)->involving($subject)->verb('inspect');
        if ($builder instanceof FeedBuilder) {
            $builder->query(fn (ActivityBuilder $query) => $query->where('verb', 'inspect')->orWhere('verb', 'ignore'));
        }
        expect($builder instanceof FeedBuilder ? array_column($builder->get()->toArray(), 'id') : $builder->pluck('uid')->all())->toBe([$wanted->uid]);
        $builder->{$type}(Delivery::class);
        expect($builder instanceof FeedBuilder ? $builder->get()->toArray() : $builder->get()->all())->toBeEmpty();
    }
})->with(['origin', 'result', 'instrument', 'location', 'generator'])->with([true, false]);

class RoleIdentitySubjectFeed extends Feed
{
    public function __construct(protected string $role, protected Model $subject, protected bool $typeOnly) {}

    protected function scope(FeedBuilder $feed): void
    {
        $method = $this->role.($this->typeOnly ? 'Type' : '');
        $feed->{$method}($this->subject);
    }
}

it('locks new Feed-class identities and types together while allowing other roles', function (string $role, bool $typeOnly) {
    $subject = Storyfeed::party('Connected App');
    $wanted = Storyfeed::activity()->actor('Operator')->action('inspect')->{$role}($subject)->publish();
    $feed = RoleIdentitySubjectFeed::make($role, $subject, $typeOnly);
    expect($feed->boundRoles())->toBe([$role])
        ->and(fn () => $feed->{$role}($subject))->toThrow(FeedMisconfigured::class, 'cannot be rebound')
        ->and(fn () => $feed->{$role.'Type'}(Party::class))->toThrow(FeedMisconfigured::class, 'cannot be rebound');
    expect(array_column($feed->actor('Operator')->log()->get()->toArray(), 'id'))->toBe([$wanted->uid])
        ->and($feed->boundRoles())->toBe([$role, 'actor']);
})->with(['origin', 'result', 'instrument', 'location', 'generator'])->with([true, false]);

it('recounts Live groups and aggregates and pages matching children through named feeds', function (string $role, bool $curate) {
    config(['storyfeed.grouping.curate' => $curate, 'storyfeed.grouping.children_limit' => 2]);
    Storyfeed::axes([Axis::make('repeat')->key('v:d')->fallback()], merge: false);
    $party = Storyfeed::party('Connected App');
    $expectedIds = [];
    foreach (['inspect_alpha', 'inspect_beta', 'inspect_gamma'] as $verb) {
        Story::verb($verb)->grouped(Group::repeat()->headline(':count things'));
        foreach (range(1, 3) as $n) {
            $file = Delivery::create(['tracking_number' => $verb.$n]);
            $expectedIds[] = Storyfeed::activity()->actor('Operator')->action($verb, $file)->{$role}($party)->publish()->uid;
        }
        Storyfeed::activity()->actor('Excluded')->action($verb, Delivery::create(['tracking_number' => $verb.'excluded']))->{$role}('Other App')->publish();
    }
    expect(array_column(Storyfeed::feed()->live()->get()->toArray(), 'count'))->toBe([4, 4, 4]);
    Storyfeed::feeds(['connected' => fn (FeedBuilder $feed) => $feed->{$role}('Connected App')->live()]);
    $builder = Storyfeed::feed('connected');
    $expected = $builder->get()->toArray();
    $seen = [];
    $cursor = null;
    do {
        $page = (clone $builder)->limit(1)->cursorPaginate(cursor: $cursor);
        array_push($seen, ...$page->toArray()['data']);
        $cursor = $page->nextCursor();
        expect(count($seen))->toBeLessThanOrEqual(3);
    } while ($cursor !== null);
    expect($seen)->toBe($expected)->toHaveCount(3);
    foreach ($seen as $node) {
        expect($node['kind'])->toBe('group')->and($node['count'])->toBe(3)
            ->and($node['children'])->toHaveCount(2)->and($node['distinct']['objects'])->toBe(3)
            ->and($node['distinct']['actors'])->toBe(1)->and($node['sample']['actors'])->toHaveCount(1)
            ->and(array_column($node['children'], 'id'))->each->toBeIn($expectedIds);
    }
})->with(['origin', 'result', 'instrument', 'location', 'generator'])->with([true, false]);

it('pages only matching identities in Log and Live including imported solos', function (string $role, string $mode, bool $imported) {
    $party = Storyfeed::party('Connected App');
    $ids = [];
    foreach (range(1, 3) as $n) {
        $ids[] = Storyfeed::activity()->anonymously()->action('inspect_'.$n)->{$role}($party)->publishedAt(now()->subMinutes(4 - $n))->publish()->uid;
        Storyfeed::activity()->anonymously()->action('inspect_'.$n)->{$role}('Other App')->publishedAt(now()->subMinutes(4 - $n))->publish();
    }
    if ($imported) {
        Grouping::query()->delete();
    }
    $builder = Storyfeed::feed()->{$role}($party)->{$mode}();
    $seen = [];
    $cursor = null;
    do {
        request()->query->set('cursor', $cursor?->encode());
        $page = (clone $builder)->cursorPaginate(1);
        array_push($seen, ...array_map(fn ($item) => $item->id(), $page->items()));
        $cursor = $page->nextCursor();
        expect(count($seen))->toBeLessThanOrEqual(3);
    } while ($cursor !== null);
    request()->query->remove('cursor');
    expect($seen)->toBe(array_reverse($ids));
})->with(['origin', 'result', 'instrument', 'location', 'generator'])->with(['log', 'live'])->with([true, false]);

it('uses existing morph-pair indexes for new identity predicates on every engine', function () {
    $rows = [];
    foreach (range(1, 2000) as $id) {
        $row = ['uid' => (string) Str::ulid(), 'verb' => 'inspect', 'published_at' => now()];
        foreach (['origin', 'result', 'instrument'] as $role) {
            $row[$role.'_type'] = 'delivery';
            $row[$role.'_id'] = (string) $id;
        }
        $rows[] = $row;
    }
    foreach (array_chunk($rows, 100) as $chunk) {
        DB::table('feed_activities')->insert($chunk);
    }
    $driver = DB::getDriverName();
    DB::statement(in_array($driver, ['mysql', 'mariadb'], true) ? 'ANALYZE TABLE feed_activities' : 'ANALYZE feed_activities');
    $model = new Delivery;
    $model->setAttribute('id', 1);
    foreach (['origin', 'result', 'instrument'] as $role) {
        $query = Activity::query()->{$role}($model)->published()->select('id')->orderByDesc('published_at')->orderByDesc('id');
        $plan = json_encode(DB::select(($driver === 'sqlite' ? 'EXPLAIN QUERY PLAN ' : 'EXPLAIN ').$query->toSql(), $query->getBindings()), JSON_THROW_ON_ERROR);
        expect($plan)->toContain('feed_activities_'.$role.'_type_'.$role.'_id_index');
    }
});
