<?php

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Http\Request;
use Storyfeed\Exceptions\FeedMisconfigured;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Sources\ArraySource;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

beforeEach(function () {
    $this->sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
});

function pingGroup(User $actor, int $count, array $at = []): void
{
    foreach (range(1, $count) as $i) {
        Storyfeed::activity()->actor($actor)->verb('ping')
            ->publishedAt($at[$i] ?? now()->subSeconds($count + 1 - $i))
            ->publish();
    }
}

/** Every member id, following next_cursor to the end. */
function allMembers($feed, string $group, int $perPage): array
{
    $ids = [];
    $cursor = null;
    $pages = 0;

    do {
        $page = $feed->members($group, $perPage, cursor: $cursor);
        $ids = [...$ids, ...collect($page->items())->pluck('id')->all()];
        $cursor = $page->nextCursor();
        $pages++;
    } while ($cursor !== null);

    return [$ids, $pages];
}

it('pages a group of 60 across three pages, newest first, past its children', function () {
    pingGroup($this->sally, 60);

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($node['kind'])->toBe('group')
        ->and($node['count'])->toBe(60)
        ->and($node['children_truncated'])->toBeTrue();

    [$ids, $pages] = allMembers(Storyfeed::feed(), $node['id'], 25);

    $expected = Storyfeed::feed()->log()->limit(100)->get()->collect()->pluck('id')->all();

    expect($pages)->toBe(3)
        ->and($ids)->toBe($expected)
        ->and(array_slice($ids, 0, 25))->toBe(collect($node['children'])->pluck('id')->all());
});

it('returns a Laravel cursor paginator of activity nodes', function () {
    pingGroup($this->sally, 5);
    $group = Storyfeed::feed()->get()->toArray()['items'][0]['id'];

    $this->app->instance('request', Request::create('https://example.test/groups'));
    $page = Storyfeed::feed()->members($group, 2);

    expect($page)->toBeInstanceOf(CursorPaginator::class)
        ->and($page->perPage())->toBe(2)
        ->and($page->hasMorePages())->toBeTrue()
        ->and($page->toArray()['items'])->each->toHaveKey('kind', 'activity');

    $this->app->instance('request', Request::create($page->nextPageUrl()));
    $second = Storyfeed::feed()->members($group, 2);

    expect(collect($second->items())->pluck('id')->intersect(collect($page->items())->pluck('id')))->toBeEmpty()
        ->and($second->items())->toHaveCount(2);
});

it('reads members through the feed scope', function () {
    $acme = Customer::create(['name' => 'Acme']);
    $globex = Customer::create(['name' => 'Globex']);

    foreach (range(1, 30) as $i) {
        Storyfeed::activity()->actor($this->sally)->verb('ping')
            ->context($i % 3 === 0 ? $globex : $acme)
            ->publishedAt(now()->subSeconds(31 - $i))->publish();
    }

    $feed = Storyfeed::feed()->context($acme);
    $node = $feed->get()->toArray()['items'][0];

    [$ids] = allMembers($feed, $node['id'], 7);

    expect($ids)->toHaveCount($node['count'])
        ->and($ids)->toBe($feed->log()->limit(100)->get()->collect()->pluck('id')->all())
        ->and(collect($feed->members($node['id'], 100)->items())->pluck('context.id')->unique()->all())
        ->toBe([(string) $acme->id]);
});

it('leaves out members published in the future, as the feed does', function () {
    pingGroup($this->sally, 30);
    Storyfeed::activity()->actor($this->sally)->verb('ping')->publishedAt(now()->addMinutes(5))->publish();

    $node = Storyfeed::feed()->get()->toArray()['items'][0];
    [$ids] = allMembers(Storyfeed::feed(), $node['id'], 10);

    expect($node['count'])->toBe(30)->and($ids)->toHaveCount(30);

    $this->travel(10)->minutes();

    expect(allMembers(Storyfeed::feed(), $node['id'], 10)[0])->toHaveCount(31);
});

it('still lists a member whose instrument was tombstoned', function () {
    $customers = collect(range(1, 30))->map(fn (int $i) => Customer::create(['name' => "Customer {$i}"]));

    foreach ($customers as $i => $customer) {
        Storyfeed::activity()->actor($this->sally)->verb('ping')->instrument($customer)
            ->publishedAt(now()->subSeconds(31 - $i))->publish();
    }

    $before = Storyfeed::feed()->get()->toArray()['items'][0];
    [$ids] = allMembers(Storyfeed::feed(), $before['id'], 10);

    $customers->first()->delete();

    $node = Storyfeed::feed()->get()->toArray()['items'][0];
    $members = collect(Storyfeed::feed()->members($node['id'], 100)->items());

    expect($node['id'])->toBe($before['id'])
        ->and($node['count'])->toBe(30)
        ->and($members->pluck('id')->all())->toBe($ids)
        ->and($members->last()['instrument']['type'])->toBe(FeedTombstone::MORPH_ALIAS);
});

it('reads an empty page for a group the feed cannot see', function () {
    pingGroup($this->sally, 5);
    $group = Storyfeed::feed()->get()->toArray()['items'][0]['id'];
    $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);

    $page = Storyfeed::feed()->actor($bob)->members($group);

    expect($page->items())->toBe([])->and($page->nextCursor())->toBeNull();
});

it('refuses a string that is not a group id', function (string $id) {
    Storyfeed::feed()->members($id);
})->with(['01J1K2M3N4P5Q6R7S8T9V0W1X2', 'grp_'.sha1('legacy'), 'grp_!!'])
    ->throws(InvalidArgumentException::class, 'is not a group id');

it('refuses a source feed', function () {
    Storyfeed::feed()->source(new ArraySource([]))->members('grp_x');
})->throws(FeedMisconfigured::class, 'cannot read a group\'s members');
