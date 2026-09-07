<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Storyfeed\Events\ActivityDeleted;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Events\BatchClosed;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Batch;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The package's three events implement ShouldDispatchAfterCommit: with no
 * transaction open they dispatch at once; inside one they wait for the
 * OUTERMOST commit; a rollback fires nothing. Every "level" below is
 * DB::transactionLevel() as the listener saw it.
 */

function sally(): User
{
    return User::firstOrCreate(['email' => 'sally@example.com'], ['name' => 'Sally']);
}

function rolledBack(Closure $work): void
{
    try {
        DB::transaction(function () use ($work) {
            $work();

            throw new RuntimeException('roll it back');
        });
    } catch (RuntimeException) {
        // expected
    }
}

it('dispatches ActivityPublished immediately when no transaction is open', function () {
    $levels = [];
    Event::listen(ActivityPublished::class, function () use (&$levels) {
        $levels[] = DB::transactionLevel();
    });

    Storyfeed::activity()->verb('ping')->publish();

    expect($levels)->toBe([0]);
});

it('holds ActivityPublished until the consumer\'s outermost commit', function () {
    $levels = [];
    Event::listen(ActivityPublished::class, function () use (&$levels) {
        $levels[] = DB::transactionLevel();
    });

    $seenInside = null;

    DB::transaction(function () use (&$levels, &$seenInside) {
        DB::transaction(fn () => Storyfeed::activity()->verb('ping')->publish());

        // Two levels down, both committed as savepoints: still nothing.
        $seenInside = $levels;
    });

    expect($seenInside)->toBe([])
        ->and($levels)->toBe([0]);
});

it('fires no ActivityPublished for a publish whose transaction rolled back', function () {
    // Fails without ShouldDispatchAfterCommit: the listener ran at level 1
    // for a row that never existed.
    $runs = 0;
    Event::listen(ActivityPublished::class, function () use (&$runs) {
        $runs++;
    });

    rolledBack(fn () => Storyfeed::activity()->verb('ping')->publish());

    expect($runs)->toBe(0)
        ->and(Activity::query()->withTrashed()->count())->toBe(0);
});

it('treats the composite parent the same way', function () {
    $levels = [];
    Event::listen(ActivityPublished::class, function () use (&$levels) {
        $levels[] = DB::transactionLevel();
    });

    $files = [Delivery::create(['tracking_number' => 'F-1']), Delivery::create(['tracking_number' => 'F-2'])];

    rolledBack(fn () => Storyfeed::activity('upload')->actor(sally())->objects($files)->publish());

    expect($levels)->toBe([]);

    DB::transaction(fn () => Storyfeed::activity('upload')->actor(sally())->objects($files)->publish());

    expect($levels)->toBe([0]);
});

it('dispatches BatchClosed only once the publish that closed the batch has committed', function () {
    // Fails without ShouldDispatchAfterCommit: the lazy close happens
    // INSIDE the publish transaction, so a listener ran at level 1 against
    // a close that was not yet durable.
    $seen = [];
    Event::listen(BatchClosed::class, function (BatchClosed $event) use (&$seen) {
        $seen[] = [
            'level' => DB::transactionLevel(),
            'closed_in_db' => Batch::findOrFail($event->batch->id)->closed_at !== null,
        ];
    });

    Storyfeed::activity()->actor(sally())->verb('ping')->publish();

    $this->travel(11)->minutes();

    Storyfeed::activity()->actor(sally())->verb('ping')->publish();

    expect($seen)->toBe([['level' => 0, 'closed_in_db' => true]]);
});

it('drops BatchClosed when the publish that would have closed the batch rolls back', function () {
    $runs = 0;
    Event::listen(BatchClosed::class, function () use (&$runs) {
        $runs++;
    });

    Storyfeed::activity()->actor(sally())->verb('ping')->publish();

    $this->travel(11)->minutes();

    rolledBack(fn () => Storyfeed::activity()->actor(sally())->verb('ping')->publish());

    expect($runs)->toBe(0)
        ->and(Batch::query()->count())->toBe(1)
        ->and(Batch::query()->first()->isOpen())->toBeTrue();
});

it('holds ActivityDeleted for the commit and drops it on rollback', function () {
    $levels = [];
    Event::listen(ActivityDeleted::class, function () use (&$levels) {
        $levels[] = DB::transactionLevel();
    });

    $activity = Storyfeed::activity()->verb('ping')->publish();

    rolledBack(fn () => $activity->delete());

    expect($levels)->toBe([])
        ->and($activity->fresh()->trashed())->toBeFalse();

    DB::transaction(fn () => $activity->delete());

    expect($levels)->toBe([0])
        ->and($activity->fresh()->trashed())->toBeTrue();
});

/*
 * The package's own ActivityDeleted listener (curation and composite
 * release) now runs after the commit too, by which time forceDelete() has
 * reset its transient isForceDeleting() flag. These two would pass outside
 * a transaction and fail inside one if the actions still keyed on the flag.
 */

it('still releases a composite\'s members when the parent is force-deleted inside a transaction', function () {
    $files = collect(range(1, 3))->map(fn ($i) => Delivery::create(['tracking_number' => "File-{$i}"]))->all();

    $parent = Storyfeed::activity('upload')->actor(sally())->objects($files)->publish();

    DB::transaction(fn () => $parent->forceDelete());

    expect(Grouping::query()->where('bucket', 'composite')->count())->toBe(0);

    $items = Storyfeed::feed()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('repeat')
        ->and($items[0]['count'])->toBe(3);
});

it('still drops a force-deleted activity\'s candidate hashes when the delete is inside a transaction', function () {
    $project = Customer::create(['name' => 'Concur']);

    foreach (['Bob', 'Sally', 'Ann'] as $name) {
        $user = User::firstOrCreate(['email' => strtolower($name).'@example.com'], ['name' => $name]);
        Storyfeed::activity()->actor($user)->verb('upload', Delivery::create(['tracking_number' => "{$name}-1"]))->for($project)->publish();
    }

    $ann = Activity::query()->whereHas('cachedActor', fn ($q) => $q->where('label', 'Ann'))->first();

    DB::transaction(fn () => $ann->forceDelete());

    expect(Grouping::query()->where('activity_id', $ann->getKey())->count())->toBe(0)
        ->and(Grouping::query()->where('bucket', 'actors')->where('winner', true)->count())->toBe(0)
        ->and(Storyfeed::feed()->get()->toArray()['items'])->toHaveCount(2);
});
