<?php

use Storyfeed\Actions\CloseBatches;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Batch;
use Storyfeed\Models\Grouping;
use Storyfeed\Serialization\ActivitySerializer;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

function tomas(): User
{
    return User::firstOrCreate(['email' => 'tomas@example.com'], ['name' => 'Tomás Rivera']);
}

function sixFiles(): array
{
    return collect(range(1, 6))
        ->map(fn ($i) => Delivery::create(['tracking_number' => "File-{$i}.pdf"]))
        ->all();
}

it('publishes an explicit composite: one story, six atomic members', function () {
    Storyfeed::aggregateGrammar(['composite.upload' => ':actor uploaded :count files to :target']);

    $campaign = Customer::create(['name' => 'Spring Campaign']);

    $parent = Storyfeed::activity('upload')
        ->actor(tomas())
        ->objects(sixFiles())
        ->for($campaign)
        ->publish();

    expect(Activity::query()->count())->toBe(7)
        ->and($parent->object_type)->toBeNull();

    $items = Storyfeed::feed()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['kind'])->toBe('group')
        ->and($items[0]['axis'])->toBe('composite')
        ->and($items[0]['count'])->toBe(6)
        ->and($items[0]['children'])->toHaveCount(6)
        ->and($items[0]['headline_template'])->toBe(':actor uploaded :count files to :target')
        ->and($items[0]['exemplars']['object'])->toBeNull()
        ->and($items[0]['exemplars']['target']['label'])->toBe('Spring Campaign');
});

it('shows the atomic timeline in flat mode — members yes, story no', function () {
    Storyfeed::activity('upload')->actor(tomas())->objects(sixFiles())->publish();

    $items = Storyfeed::feed()->flat()->limit(10)->get()->toArray()['items'];

    expect($items)->toHaveCount(6)
        ->and(collect($items)->pluck('kind')->unique()->values()->all())->toBe(['activity'])
        ->and(collect($items)->pluck('object.label')->filter())->toHaveCount(6);
});

it('shows composites in grouped mode — authored stories are not inference', function () {
    Storyfeed::activity('upload')->actor(tomas())->objects(sixFiles())->publish();

    $items = Storyfeed::feed()->grouped()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('composite')
        ->and($items[0]['count'])->toBe(6);
});

it('rejects mixing object() and objects()', function () {
    expect(fn () => Storyfeed::activity('upload', Delivery::create(['tracking_number' => 'x']))
        ->objects(sixFiles()))
        ->toThrow(InvalidArgumentException::class);
});

it('auto-bundles a collectable run when the batch closes', function () {
    Storyfeed::collectables(['delivery']);

    $campaign = Customer::create(['name' => 'Spring Campaign']);

    foreach (range(1, 6) as $i) {
        Storyfeed::activity()
            ->actor(tomas())
            ->verb('upload', Delivery::create(['tracking_number' => "File-{$i}.pdf"]))
            ->for($campaign)
            ->publish();
    }

    // Pre-close: inference serves it (a repeat group).
    expect(Storyfeed::feed()->get()->toArray()['items'][0]['axis'])->toBe('repeat');

    $this->travel(11)->minutes();
    (new CloseBatches)();

    $items = Storyfeed::feed()->get()->toArray()['items'];

    // Post-close: the run is a minted story; axis rows are gone.
    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('composite')
        ->and($items[0]['count'])->toBe(6)
        ->and(Grouping::query()->where('bucket', 'repeat')->whereIn(
            'activity_id',
            Activity::query()->whereNotNull('object_id')->pluck('id'),
        )->count())->toBe(0);

    // Idempotent: closing again mints nothing new.
    (new CloseBatches)();

    expect(Activity::query()->count())->toBe(7);
});

it('never auto-bundles undesignated types, same-object runs, or singles', function () {
    $campaign = Customer::create(['name' => 'Spring Campaign']);

    // Undesignated: Delivery is NOT collectable here.
    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor(tomas())->verb('upload', Delivery::create(['tracking_number' => "U-{$i}"]))->for($campaign)->publish();
    }

    $this->travel(11)->minutes();
    (new CloseBatches)();

    expect(Grouping::query()->where('bucket', 'composite')->count())->toBe(0)
        ->and(Storyfeed::feed()->get()->toArray()['items'][0]['axis'])->toBe('repeat');

    // Same-object run of a collectable: the object axis's story, not a collection.
    Storyfeed::collectables(['delivery']);

    $doc = Delivery::create(['tracking_number' => 'Same.pdf']);
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    Storyfeed::activity()->actor($sally)->verb('revise', $doc)->publish();
    Storyfeed::activity()->actor($sally)->verb('revise', $doc)->publish();

    // Single collectable act: mints nothing (collection-of-one collapse).
    $ann = User::create(['name' => 'Ann', 'email' => 'ann@example.com']);
    Storyfeed::activity()->actor($ann)->verb('upload', Delivery::create(['tracking_number' => 'Solo.pdf']))->publish();

    $this->travel(11)->minutes();
    (new CloseBatches)();

    expect(Grouping::query()->where('bucket', 'composite')->count())->toBe(0);

    $axes = collect(Storyfeed::feed()->limit(10)->get()->toArray()['items']);

    expect($axes->firstWhere('count', 2)['axis'])->toBe('object');
});

it('re-decides abandoned clusters when a run is claimed', function () {
    Storyfeed::collectables(['delivery']);

    $campaign = Customer::create(['name' => 'Spring Campaign']);

    // Three actors upload to one target: an actors cluster of 3 — but two of
    // the three uploads are Tomás's collectable run? No: make Tomás upload 2
    // files + Bob and Ann one each. actors cluster = 4 members, 3 distinct
    // actors -> actors wins pre-close. Tomás's 2-file run is claimed at
    // close; the remaining actors cluster (Bob, Ann) drops below min_actors
    // and must fall back to repeat/solo — not keep claiming actors.
    foreach (range(1, 2) as $i) {
        Storyfeed::activity()->actor(tomas())->verb('upload', Delivery::create(['tracking_number' => "T-{$i}"]))->for($campaign)->publish();
    }

    foreach (['Bob', 'Ann'] as $name) {
        $u = User::create(['name' => $name, 'email' => strtolower($name).'@example.com']);
        Storyfeed::activity()->actor($u)->verb('upload', Delivery::create(['tracking_number' => "O-{$name}"]))->for($campaign)->publish();
    }

    expect(Storyfeed::feed()->get()->toArray()['items'][0]['axis'])->toBe('actors');

    $this->travel(11)->minutes();
    (new CloseBatches)();

    $items = collect(Storyfeed::feed()->limit(10)->get()->toArray()['items']);

    expect($items->firstWhere('axis', 'composite')['count'])->toBe(2)
        ->and(Grouping::query()->where('bucket', 'actors')->where('winner', true)->count())->toBe(0);
});

it('releases members back to inference when the story is force-deleted', function () {
    $parent = Storyfeed::activity('upload')->actor(tomas())->objects(sixFiles())->publish();

    $parent->forceDelete();

    expect(Grouping::query()->where('bucket', 'composite')->count())->toBe(0);

    // The events outlive the story: back to inference as a repeat group.
    $items = Storyfeed::feed()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('repeat')
        ->and($items[0]['count'])->toBe(6);
});

it('serializes the composite object as an OrderedCollection', function () {
    $campaign = Customer::create(['name' => 'Spring Campaign']);

    $parent = Storyfeed::activity('upload')->actor(tomas())->objects(sixFiles())->for($campaign)->publish();

    $document = app(ActivitySerializer::class)->activity(
        $parent->fresh(['cachedActor', 'cachedObject', 'cachedTarget', 'cachedContext']),
    );

    expect($document['object']['type'])->toBe('OrderedCollection')
        ->and($document['object']['totalItems'])->toBe(6)
        ->and($document['object']['orderedItems'])->toHaveCount(6)
        ->and($document['object']['orderedItems'][0]['name'])->toContain('File-');
});

it('records members on the fake for per-object assertions', function () {
    Storyfeed::fake();

    $files = [
        new Delivery(['tracking_number' => 'A.pdf']),
        new Delivery(['tracking_number' => 'B.pdf']),
    ];

    Storyfeed::activity('upload')->actor(new User(['name' => 'T', 'email' => 't@example.com']))->objects($files)->publish();

    Storyfeed::assertPublished('upload', $files[0]);
    Storyfeed::assertPublished('upload', $files[1]);
});

it('backfills history with storyfeed:bundle after late Collectable adoption', function () {
    // History recorded BEFORE the type was designated: batch closes, no
    // bundling (undesignated at close time).
    foreach (range(1, 4) as $i) {
        Storyfeed::activity()->actor(tomas())->verb('upload', Delivery::create(['tracking_number' => "H-{$i}"]))->publish();
    }

    $this->travel(11)->minutes();
    (new CloseBatches)();

    expect(Grouping::query()->where('bucket', 'composite')->count())->toBe(0);

    // The model adopts Collectable later; automatic bundling is
    // future-only, so the explicit backfill walks closed batches.
    Storyfeed::collectables(['delivery']);

    $this->artisan('storyfeed:bundle')->assertSuccessful();

    $items = Storyfeed::feed()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('composite')
        ->and($items[0]['count'])->toBe(4);

    // Idempotent: a second sweep mints nothing.
    $this->artisan('storyfeed:bundle')->assertSuccessful();

    expect(Activity::query()->count())->toBe(5);
});

it('partitions backfilled runs by day — a giant seeded batch never merges days', function () {
    Storyfeed::collectables(['delivery']);
    config()->set('storyfeed.grouping.composite.auto', false);

    // A seeder's shape: one actor, uploads spread across two DAYS, all
    // landing in one batch (batch windows key on wall-clock, not
    // publishedAt).
    foreach ([now()->subDays(2), now()->subDay()] as $day) {
        foreach (range(1, 3) as $i) {
            Storyfeed::activity()
                ->actor(tomas())
                ->verb('upload', Delivery::create(['tracking_number' => "D{$day->day}-{$i}"]))
                ->publishedAt($day->copy()->addMinutes($i))
                ->publish();
        }
    }

    expect(Batch::query()->count())->toBe(1);

    $this->travel(11)->minutes();
    (new CloseBatches)();

    // auto=false: the automatic path respects the config...
    expect(Grouping::query()->where('bucket', 'composite')->count())->toBe(0);

    // ...but the explicit command is intent, and partitions by day.
    $this->artisan('storyfeed:bundle')->assertSuccessful();

    $items = collect(Storyfeed::feed()->limit(10)->get()->toArray()['items']);

    expect($items)->toHaveCount(2)
        ->and($items->pluck('axis')->unique()->values()->all())->toBe(['composite'])
        ->and($items->pluck('count')->all())->toBe([3, 3]);
});
