<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Storyfeed\Actions\RebuildSnapshots;
use Storyfeed\Actions\TrickleSnapshots;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Exceptions\UnauthoredActivity;
use Storyfeed\Exceptions\UnknownVerb;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

/**
 * The claims `docs/backfilling.md` makes, as assertions.
 *
 * That guide's entire value is that it is TRUE — it tells an adopter the order
 * to run repair commands in, and one of those orders is a one-way door. A doc
 * page cannot notice when the behaviour it describes changes; this file can.
 * Every test here is named after the sentence it pins.
 *
 * These are deliberately BEHAVIOURAL, not unit tests of the actions involved:
 * what the guide promises is what an adopter observes from the outside — the
 * feed, the counts, the doctor findings — so that is what is asserted.
 */
beforeEach(function () {
    $this->ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $this->order = Customer::create(['name' => 'Order 1001']);
});

function rawInsert(string $uid, User $actor, Customer $object, string $publishedAt, string $verb = 'order.note'): void
{
    DB::table('feed_activities')->insert([
        'uid' => $uid,
        'verb' => $verb,
        'actor_type' => 'user',
        'actor_id' => $actor->id,
        'object_type' => 'customer',
        'object_id' => $object->id,
        'published_at' => $publishedAt,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('buckets backdated activities by the day they happened, not the day they were imported', function () {
    for ($i = 5; $i > 0; $i--) {
        Storyfeed::activity()->actor($this->ines)
            ->verb('order.note', $this->order)
            ->publishedAt(now()->subDays($i))
            ->publish();
    }

    $hashes = DB::table('feed_groupings')->where('bucket', 'repeat')->pluck('hash');

    expect($hashes)->toHaveCount(5)
        ->and($hashes->unique())->toHaveCount(5);
});

it('collapses a bulk backdated import into ONE batch, because batches are stamped against the wall clock', function () {
    foreach (range(1, 6) as $i) {
        Storyfeed::activity()->actor($this->ines)
            ->verb("order.step{$i}", $this->order)
            ->publishedAt(now()->subMonths($i))
            ->publish();
    }

    expect(DB::table('feed_batches')->count())->toBe(1)
        ->and(DB::table('feed_groupings')->where('bucket', 'batch')->count())->toBe(6);

    // …and the guide's mitigation for the fact above: the rendered feed is
    // unaffected, because `batch` is not in the curation policy.
    $winners = DB::table('feed_groupings')->where('winner', true)->pluck('bucket')->unique();

    expect($winners->all())->toBe(['repeat'])
        ->and(collect(Storyfeed::feed()->get()->items())->pluck('kind')->unique()->all())->toBe(['activity']);
});

it('paginates history that shares one timestamp without dropping or repeating a row', function () {
    // Date-only legacy columns produce exactly this: every row at midnight.
    foreach (range(1, 15) as $i) {
        Storyfeed::activity()->actor($this->ines)
            ->verb('order.note', Customer::create(['name' => "Order {$i}"]))
            ->publishedAt('2024-03-01 00:00:00')
            ->publish();
    }

    $seen = [];
    $cursor = null;
    $pages = 0;

    do {
        $page = Storyfeed::feed()->log()->limit(5)->cursor($cursor)->get();
        $seen = array_merge($seen, collect($page->items())->pluck('id')->all());
        $cursor = $page->nextCursor();
        $pages++;
    } while ($cursor !== null && $pages < 10);

    expect($seen)->toHaveCount(15)
        ->and(array_unique($seen))->toHaveCount(15);
});

it('renders a hand-rolled timeline shape only in log() mode — the default groups it', function () {
    foreach (['09:00', '10:00', '11:00'] as $time) {
        Storyfeed::activity()->actor($this->ines)
            ->verb('order.note', $this->order)
            ->publishedAt("2024-03-01 {$time}:00")
            ->publish();
    }

    expect(Storyfeed::feed()->log()->get()->items())->toHaveCount(3)
        ->and(Storyfeed::feed()->get()->items())->toHaveCount(1)
        ->and(Storyfeed::feed()->get()->items()[0]['kind'])->toBe('group');
});

it('leaves raw-inserted rows ungrouped forever if storyfeed:rebuild runs before the trickle', function () {
    foreach (range(1, 4) as $i) {
        rawInsert("raw-{$i}", $this->ines, $this->order, "2024-03-01 0{$i}:00:00");
    }

    // rebuild snapshots and caches links — and writes no grouping rows at all.
    (new RebuildSnapshots)();

    expect(DB::table('feed_groupings')->count())->toBe(0);

    // The one-way door: the trickle converges legacy rows into groups, but only
    // looks at UNCACHED activities, and rebuild just cached every one of them.
    expect((new TrickleSnapshots)())->toMatchArray(['snapshotted' => 0])
        ->and(DB::table('feed_groupings')->count())->toBe(0);

    // Nothing says so: backlog counts uncached entities, not missing groups.
    expect(Storyfeed::doctor(['backlog'])->all())->toBeEmpty();

    // The way out, and the way not to get in.
    $this->artisan('storyfeed:curate --rehash')->assertSuccessful();

    expect(DB::table('feed_groupings')->where('winner', true)->count())->toBe(4)
        ->and(Storyfeed::feed()->get()->items())->toHaveCount(1)
        ->and(Storyfeed::feed()->get()->items()[0]['kind'])->toBe('group');
});

it('hides raw-inserted rows from involving() until storyfeed:participants runs', function () {
    rawInsert('raw-1', $this->ines, $this->order, '2024-03-01 09:00:00');

    // The global feed looks perfectly healthy while the per-entity timeline —
    // the page a migration is usually replacing — is empty.
    expect(Storyfeed::feed()->log()->get()->items())->toHaveCount(1)
        ->and($this->order->storyfeed()->log()->get()->items())->toHaveCount(0);

    expect(collect(Storyfeed::doctor(['participants'])->all())->pluck('code')->all())
        ->toContain('participants.unindexed');

    $this->artisan('storyfeed:participants')->assertSuccessful();

    expect($this->order->storyfeed()->log()->get()->items())->toHaveCount(1);
});

it('resolves headlines at read time, so grammar may be registered after the backfill', function () {
    Storyfeed::activity()->actor($this->ines)
        ->verb('order.placed', $this->order)
        ->publishedAt('2024-03-01 09:00:00')
        ->publish();

    expect(Storyfeed::feed()->log()->get()->items()[0]['headline_template'])->toBeNull();

    Storyfeed::grammar(['customer.order.placed' => ':actor placed :object']);

    $item = Storyfeed::feed()->log()->get()->items()[0];

    // String grammar hands the template to the renderer; `headline` stays null
    // by design (a closure entry is what renders server-side).
    expect($item['headline_template'])->toBe(':actor placed :object')
        ->and($item['headline'])->toBeNull();
});

it('duplicates a naive re-import, and dedupes on a source key recorded in data', function () {
    foreach ([1, 2] as $pass) {
        Storyfeed::activity()->actor($this->ines)
            ->verb('order.placed', $this->order)
            ->publishedAt('2024-03-01 09:00:00')
            ->publish();
    }

    expect(Activity::query()->where('verb', 'order.placed')->count())->toBe(2);

    foreach ([1, 2] as $pass) {
        $done = Activity::query()->where('data->import', 'orders-v1')
            ->pluck('data')->map(fn ($data) => $data['source_id'])->flip();

        if ($done->has(42)) {
            continue;
        }

        Storyfeed::activity()->actor($this->ines)
            ->verb('order.paid', $this->order)
            ->data(['import' => 'orders-v1', 'source_id' => 42])
            ->publishedAt('2024-03-02 09:00:00')
            ->publish();
    }

    expect(Activity::query()->where('verb', 'order.paid')->count())->toBe(1);
});

it('collapses legitimately repeated history under publishAndReplace(), which is why a transition log must not use it', function () {
    // pending → paid → refunded → paid: the second `paid` is real history.
    foreach (['2024-03-01', '2024-03-03'] as $day) {
        Storyfeed::activity()->actor($this->ines)
            ->verb('order.paid', $this->order)
            ->publishedAt("{$day} 09:00:00")
            ->publishAndReplace();
    }

    expect(Activity::query()->where('verb', 'order.paid')->count())->toBe(1);
});

it('dispatches ActivityPublished for every backfilled row, and Event::forget silences it', function () {
    $fired = 0;

    Event::listen(ActivityPublished::class, function () use (&$fired) {
        $fired++;
    });

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($this->ines)
            ->verb('order.note', $this->order)
            ->publishedAt("2024-03-0{$i} 09:00:00")
            ->publish();
    }

    expect($fired)->toBe(3);

    Event::forget(ActivityPublished::class);

    foreach (range(4, 6) as $i) {
        Storyfeed::activity()->actor($this->ines)
            ->verb('order.note', $this->order)
            ->publishedAt("2024-03-0{$i} 09:00:00")
            ->publish();
    }

    expect($fired)->toBe(3)
        ->and(Activity::query()->count())->toBe(6);
});

it('prunes an imported row whose entity cannot be resolved, rather than skipping it', function () {
    rawInsert('gone-1', $this->ines, $this->order, '2024-03-01 09:00:00');

    DB::table('feed_activities')->where('uid', 'gone-1')->update(['object_id' => 9999]);

    expect((new TrickleSnapshots)())->toMatchArray(['pruned' => 1])
        ->and(Activity::query()->count())->toBe(0);
});

it('refuses an unauthored or undeclared verb only when the strict switches are on', function () {
    config()->set('storyfeed.grammar.strict', true);

    expect(fn () => Storyfeed::activity()->actor($this->ines)
        ->verb('order.placed', $this->order)->publishedAt('2024-03-01')->publish())
        ->toThrow(UnauthoredActivity::class);

    config()->set('storyfeed.grammar.strict', false);
    config()->set('storyfeed.verbs.strict', true);

    expect(fn () => Storyfeed::activity()->actor($this->ines)
        ->verb('order.placed', $this->order)->publishedAt('2024-03-01')->publish())
        ->toThrow(UnknownVerb::class);
});

it('reports freshness.stale straight after a successful history-only import', function () {
    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($this->ines)
            ->verb('order.note', $this->order)
            ->publishedAt(now()->subYear()->addDays($i))
            ->publish();
    }

    expect(collect(Storyfeed::doctor(['freshness'])->all())->pluck('code')->all())
        ->toBe(['freshness.stale']);
});
