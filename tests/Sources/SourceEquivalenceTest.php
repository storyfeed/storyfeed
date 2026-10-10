<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Sources\ArraySource;
use Storyfeed\Sources\SourceItem;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The oracle for every source: the same activities, stored and read through
 * SQL or handed over as items and read in memory, make the same payload.
 */

beforeEach(function () {
    config()->set('storyfeed.grouping.batch.enabled', false);

    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $bob = User::create(['name' => 'Bob', 'email' => 'bob@example.com']);
    $priya = User::create(['name' => 'Priya', 'email' => 'priya@example.com']);
    $acme = Customer::create(['name' => 'Acme']);
    $globex = Customer::create(['name' => 'Globex']);
    // Refreshed, so the stored snapshots see the column defaults the items will.
    $docs = collect(range(1, 4))->map(fn ($i) => Delivery::create(['tracking_number' => "TN-{$i}"])->refresh());
    $start = now()->subDays(2)->startOfDay()->addHours(9);

    $at = fn (int $minutes) => $start->copy()->addMinutes($minutes);

    // A repeat burst, a second burst after the quiet gap, a crowd on one
    // object, one actor across targets, solos, a party and an actorless row,
    // two rows at one instant, and a time range.
    $plan = [
        [$sally, 'upload', $docs[0], $acme, 0],
        [$sally, 'upload', $docs[1], $acme, 3],
        [$sally, 'upload', $docs[2], $acme, 6],
        [$sally, 'upload', $docs[3], $acme, 60],
        [$sally, 'upload', $docs[0], $globex, 62],
        [$bob, 'comment', $docs[0], null, 70],
        [$sally, 'comment', $docs[0], null, 72],
        [$priya, 'comment', $docs[0], null, 74],
        [$bob, 'confirm', $docs[1], $acme, 90],
        [$bob, 'confirm', $docs[2], $globex, 91],
        [$bob, 'confirm', $docs[3], $acme, 92],
        ['Courier Bot', 'dispatch', $docs[3], null, 120],
        [null, 'expire', $docs[2], null, 150],
        [$priya, 'archive', $docs[1], null, 180],
        [$bob, 'archive', $docs[3], null, 180],
        [$priya, 'revise', $docs[0], null, 200],
        [$priya, 'revise', $docs[0], null, 202],
    ];

    foreach ($plan as [$actor, $verb, $object, $target, $minutes]) {
        $pending = $actor === null
            ? Storyfeed::anonymous()->verb($verb, $object)
            : Storyfeed::activity()->actor($actor)->verb($verb, $object);

        $pending->when($target, fn ($p) => $p->target($target))
            ->when($verb === 'archive', fn ($p) => $p->startsAt($at(0))->endsAt($at($minutes)))
            ->publishedAt($at($minutes))->publish();
    }

    // The same rows as items, under the stored ids, so every derived hash
    // and group id has to match too.
    $this->items = Activity::query()->with(['actor', 'object', 'target'])->orderBy('id')->get()
        ->map(fn (Activity $activity) => SourceItem::make(
            verb: $activity->verb,
            publishedAt: $activity->published_at,
            actor: $activity->actor_type === 'storyfeed.party' ? 'Courier Bot' : $activity->actor,
            object: $activity->object,
            target: $activity->target,
            id: $activity->uid,
            startsAt: $activity->starts_at,
            endsAt: $activity->ends_at,
        ))
        ->all();
});

/** A payload without what only storage carries: the token, and a stored party's row id. */
function comparable(array $payload): array
{
    unset($payload['sync_token'], $payload['next_cursor']);

    $walk = function (array $node) use (&$walk): array {
        // A stored party is filed under its row id, a source's under its key.
        if (($node['type'] ?? null) === 'storyfeed.party') {
            $node['id'] = 'party';
        }

        return array_map(fn ($value) => is_array($value) ? $walk($value) : $value, $node);
    };

    return $walk(json_decode(json_encode($payload), true));
}

it('reads the same payload as the database', function (string $mode) {
    $stored = Storyfeed::feed()->{$mode}()->cursorPaginate()->toArray();

    $sourced = Storyfeed::feed()->source(new ArraySource($this->items))->{$mode}()->cursorPaginate()->toArray();

    expect(comparable($sourced))->toEqual(comparable($stored));

    if ($mode === 'live') {
        expect(collect($stored['data'])->where('kind', 'group')->pluck('axis')->sort()->values()->all())
            ->toBe(['actors', 'object', 'repeat', 'targets']);
    }
})->with(['live', 'log']);

it('pages the same way as the database', function (string $mode) {
    $read = function (?Closure $source) use ($mode) {
        $ids = [];
        $cursor = null;

        do {
            $feed = Storyfeed::feed()->{$mode}()->limit(2);
            $page = ($source ? $source($feed) : $feed)->cursorPaginate(cursor: $cursor);
            $ids = [...$ids, ...$page->getCollection()->pluck('id')];
            $cursor = $page->nextCursor();
        } while ($cursor !== null);

        return $ids;
    };

    $stored = $read(null);

    expect($read(fn ($feed) => $feed->source(new ArraySource($this->items))))->toBe($stored)
        ->and($stored)->toHaveCount(count(array_unique($stored)));
})->with(['live', 'log']);

it('filters the same way as the database', function (Closure $filter) {
    $stored = $filter(Storyfeed::feed()->live())->cursorPaginate()->toArray();
    $sourced = $filter(Storyfeed::feed()->live()->source(new ArraySource($this->items)))->cursorPaginate()->toArray();

    expect(array_column($sourced['data'], 'id'))->toBe(array_column($stored['data'], 'id'))
        ->and(array_column($sourced['data'], 'count'))->toBe(array_column($stored['data'], 'count'));
})->with([
    'actor' => fn ($feed) => $feed->actor(User::firstWhere('name', 'Sally')),
    'party actor' => fn ($feed) => $feed->actor('Courier Bot'),
    'object' => fn ($feed) => $feed->object(Delivery::firstWhere('tracking_number', 'TN-1')),
    'target' => fn ($feed) => $feed->target(Customer::firstWhere('name', 'Acme')),
    'verb' => fn ($feed) => $feed->verb('comment'),
    'only' => fn ($feed) => $feed->only(['upload', 'confirm']),
    'except' => fn ($feed) => $feed->except('comment'),
    'role type' => fn ($feed) => $feed->targetType(Customer::class),
    'unknown party' => fn ($feed) => $feed->actor('Nobody'),
]);

it('groups with curation off the same way as the database', function () {
    config()->set('storyfeed.grouping.curate', false);

    // Curation already stamped the stored rows; turning it off is a read-time switch.
    $stored = Storyfeed::feed()->live()->cursorPaginate()->toArray();
    $sourced = Storyfeed::feed()->live()->source(new ArraySource($this->items))->cursorPaginate()->toArray();

    expect(array_column($sourced['data'], 'id'))->toBe(array_column($stored['data'], 'id'));
});

it('hides what is not yet published, as the database does', function () {
    Storyfeed::activity()->actor(User::first())->verb('schedule', Delivery::first())->publishedAt(now()->addDay())->publish();
    $items = [...$this->items, SourceItem::make('schedule', now()->addDay(), actor: User::first(), object: Delivery::first())];

    expect(Storyfeed::feed()->source(new ArraySource($items))->log()->get()->toArray())
        ->toHaveCount(count(Storyfeed::feed()->log()->get()->toArray()));
});
