<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Storyfeed\Events\BatchClosed;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Batch;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * A batch is a sitting: what one person did in one go. The `batch` story
 * middleware puts an activity in it; each one moves `closes_at` to its own
 * published_at plus its verb's window. Customers stand in for projects and
 * deliveries for their todos.
 */

function batchOf(Activity $activity): ?Batch
{
    $hash = Grouping::query()->where('activity_id', $activity->id)->where('bucket', 'batch')->value('hash');

    return $hash === null ? null : Batch::query()->where('uid', $hash)->first();
}

function sitter(): User
{
    return User::query()->firstOrCreate(['email' => 'sally@example.com'], ['name' => 'Sally']);
}

function addTodo(string $number): Activity
{
    return Storyfeed::activity('add', Delivery::create(['tracking_number' => $number]))->actor(sitter())->publish();
}

function createProject(string $name): Activity
{
    return Storyfeed::activity('create', Customer::create(['name' => $name]))->actor(sitter())->publish();
}

it('leaves an unbatched project-create out of the todo-adds around it', function () {
    Story::for(Delivery::class)->verb('add')->batched(within: '5 minutes');
    Story::for(Customer::class)->verb('create')->unbatched();

    $first = addTodo('TN-1');
    $this->travel(2)->minutes();
    $project = createProject('Spring Campaign');
    $this->travel(2)->minutes();
    $second = addTodo('TN-2');

    $sitting = Batch::query()->sole();

    expect(batchOf($project))->toBeNull()
        ->and(batchOf($first)->is($sitting))->toBeTrue()
        ->and(batchOf($second)->is($sitting))->toBeTrue()
        ->and($sitting->activities_count)->toBe(2)
        ->and($sitting->isOpen())->toBeTrue();
});

it('neither extends nor closes a sitting with an unbatched activity', function () {
    Event::fake([BatchClosed::class]);
    Story::for(Delivery::class)->verb('add')->batched(within: '5 minutes');
    Story::for(Customer::class)->verb('create')->unbatched();

    addTodo('TN-1');
    $closesAt = Batch::query()->sole()->closes_at;

    // Past the window: a batched activity here would close the sitting.
    $this->travel(10)->minutes();
    createProject('Spring Campaign');

    $sitting = Batch::query()->sole();

    expect($sitting->isOpen())->toBeTrue()
        ->and($sitting->closes_at->equalTo($closesAt))->toBeTrue();

    Event::assertNotDispatched(BatchClosed::class);
});

it('sets closes_at to published_at plus the configured window when nothing is declared', function () {
    $activity = Storyfeed::activity('ping')->actor(sitter())->publish();

    $sitting = Batch::query()->sole();

    expect($sitting->closes_at->equalTo($activity->published_at->copy()->addMinutes(10)->startOfSecond()))->toBeTrue();
});

it('lets each verb push closes_at out by its own window, never back', function () {
    Story::for(Delivery::class)->verb('add')->batched(within: '5 minutes');
    Story::verb('comment')->batched(within: '1 hour');

    addTodo('TN-1');
    $commented = Storyfeed::activity('comment')->actor(sitter())->publish();

    // The hour-long window holds the sitting open past a five-minute one.
    $this->travel(30)->minutes();
    $late = addTodo('TN-2');

    $sitting = Batch::query()->sole();

    expect(batchOf($late)->is($sitting))->toBeTrue()
        ->and($sitting->activities_count)->toBe(3)
        ->and($sitting->closes_at->equalTo($commented->published_at->copy()->addHour()->startOfSecond()))->toBeTrue();
});

it('closes the sitting at closes_at, and the next batched activity opens another', function () {
    Event::fake([BatchClosed::class]);
    Story::for(Delivery::class)->verb('add')->batched(within: '5 minutes');

    addTodo('TN-1');
    $this->travel(5)->minutes();
    $next = addTodo('TN-2');

    $batches = Batch::query()->orderBy('id')->get();

    expect($batches)->toHaveCount(2)
        ->and($batches[0]->isOpen())->toBeFalse()
        ->and(batchOf($next)->is($batches[1]))->toBeTrue();

    Event::assertDispatchedTimes(BatchClosed::class, 1);
});

it('sweeps by closes_at with storyfeed:close-batches', function () {
    Story::verb('comment')->batched(within: '1 hour');

    Storyfeed::activity('comment')->actor(sitter())->publish();

    $this->travel(11)->minutes();
    $this->artisan('storyfeed:close-batches')->assertSuccessful();

    expect(Batch::query()->sole()->isOpen())->toBeTrue();

    $this->travel(50)->minutes();
    $this->artisan('storyfeed:close-batches')->assertSuccessful();

    expect(Batch::query()->sole()->isOpen())->toBeFalse();
});

it('still closes by a quiet window given on the command line', function () {
    Story::verb('comment')->batched(within: '1 hour');

    Storyfeed::activity('comment')->actor(sitter())->publish();

    $this->travel(11)->minutes();
    $this->artisan('storyfeed:close-batches', ['--quiet-minutes' => 10])->assertSuccessful();

    expect(Batch::query()->sole()->isOpen())->toBeFalse();
});

it('reads a bare number as minutes', function () {
    Story::verb('comment')->middleware('batch:3')->withoutMiddleware('batch');

    $activity = Storyfeed::activity('comment')->actor(sitter())->publish();

    expect(Batch::query()->sole()->closes_at->equalTo($activity->published_at->copy()->addMinutes(3)->startOfSecond()))->toBeTrue();
});

it('batches once when a verb adds a windowed batch beside the default one, the verb\'s window deciding', function () {
    Story::verb('comment')->middleware('batch:3');

    $activity = Storyfeed::activity('comment')->actor(sitter())->publish();
    $sitting = Batch::query()->sole();

    expect($sitting->activities_count)->toBe(1)
        ->and($sitting->closes_at->equalTo($activity->published_at->copy()->addMinutes(3)->startOfSecond()))->toBeTrue();
});

it('refuses an argument that is not an interval, on the first publish', function () {
    Story::verb('comment')->middleware('batch:soon');

    Storyfeed::activity('comment')->actor(sitter())->publish();
})->throws(InvalidArgumentException::class, "The batch middleware was given 'soon'");

it('is a no-op when batching is disabled, even on a verb that asks for it', function () {
    config()->set('storyfeed.grouping.batch.enabled', false);
    Story::verb('comment')->batched(within: '1 hour');

    Storyfeed::activity('comment')->actor(sitter())->publish();

    expect(Batch::query()->count())->toBe(0);
});

it('never batches an activity with no actor, even on a verb that asks for it', function () {
    Story::verb('comment')->batched(within: '1 hour');

    Storyfeed::activity('comment')->publish();

    expect(Batch::query()->count())->toBe(0);
});

it('batches a composite once, as its parent', function () {
    $parent = Storyfeed::activity('upload')->actor(sitter())
        ->objects([Delivery::create(['tracking_number' => 'TN-1']), Delivery::create(['tracking_number' => 'TN-2'])])
        ->publish();

    expect(Batch::query()->sole()->activities_count)->toBe(1)
        ->and(batchOf($parent))->not->toBeNull();
});

it('closes an open batch that has no closes_at where it always would have', function () {
    Batch::query()->create([
        'actor_type' => 'user',
        'actor_id' => 999,
        'opened_at' => now()->subMinutes(20),
        'last_activity_at' => now()->subMinutes(11),
        'activities_count' => 1,
    ]);

    $this->artisan('storyfeed:close-batches')->assertSuccessful();

    expect(Batch::query()->sole()->isOpen())->toBeFalse();
});

it('backfills closes_at on open batches when the migration runs', function () {
    $table = config('storyfeed.tables.batches', 'feed_batches');

    $open = Batch::query()->create(['actor_type' => 'user', 'actor_id' => 1, 'opened_at' => now()->subMinutes(30), 'last_activity_at' => now()->subMinutes(5)]);
    $fresh = Batch::query()->create(['actor_type' => 'user', 'actor_id' => 2, 'opened_at' => now()->subMinutes(3)]);
    $closed = Batch::query()->create(['actor_type' => 'user', 'actor_id' => 3, 'opened_at' => now()->subHour(), 'closed_at' => now()->subMinutes(40)]);

    (include __DIR__.'/../../database/migrations/add_closes_at_to_feed_batches_table.php.stub')->up();

    expect($open->fresh()->closes_at->equalTo($open->last_activity_at->copy()->addMinutes(10)))->toBeTrue()
        ->and($fresh->fresh()->closes_at->equalTo($fresh->opened_at->copy()->addMinutes(10)))->toBeTrue()
        ->and(DB::table($table)->where('id', $closed->id)->value('closes_at'))->toBeNull();
});
