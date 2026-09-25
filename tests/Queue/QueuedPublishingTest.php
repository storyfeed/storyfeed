<?php

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Queue\Attributes\DebounceFor;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\ExpectationFailedException;
use Storyfeed\Exceptions\UnknownStory;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedChange;
use Storyfeed\FeedThread;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\Models\Snapshot;
use Storyfeed\PendingActivity;
use Storyfeed\PublishQueuedActivity;
use Storyfeed\Stories\PublishQueuedStory;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Stories\Verb;
use Storyfeed\Tests\Queue\Fixtures\DebouncedDispatch;
use Storyfeed\Tests\Queue\Fixtures\PlacedDispatch;
use Storyfeed\Tests\Queue\Fixtures\QueuedDispatch;
use Storyfeed\Tests\Queue\Fixtures\UniqueDebouncedDispatch;
use Storyfeed\Tests\Queue\Fixtures\UniqueDispatch;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Queued publishing, as Laravel queues a Mailable and a Notification: a
 * PendingActivity uses Queueable and ends in `->queue()`, and a message
 * class that implements ShouldQueue is queued by `Storyfeed::publish()`.
 * The worker publishes it: story middleware, snapshots and all.
 */

beforeEach(function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', 'testing');
    config()->set('queue.failed.driver', 'null');
    config()->set('storyfeed.parties.fallback', null);
    config()->set('cache.default', 'array');
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    QueuedDispatch::$failures = [];
    QueuedDispatch::$built = 0;
    QueuedDispatch::$throws = false;
});

afterEach(function () {
    app(StoryManifest::class)->delete();
});

function queuedPublishingWork(string $queue = 'default'): void
{
    app('queue.worker')->runNextJob('database', $queue, new WorkerOptions);
}

function queuedPublishingDelivery(string $tracking = 'TN-1'): Delivery
{
    return Delivery::create(['tracking_number' => $tracking]);
}

// ── ->queue() ────────────────────────────────────────────────────────────

it('queues a publish, and the worker publishes it at the time it was queued', function () {
    $this->freezeSecond();
    $delivery = queuedPublishingDelivery();
    $queuedAt = now()->toImmutable();

    Storyfeed::activity('ship', $delivery)->queue();

    expect(Activity::count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);

    $this->travel(10)->minutes();
    queuedPublishingWork();

    $activity = Activity::sole();

    expect($activity->verb)->toBe('ship')
        ->and($activity->object_id)->toEqual($delivery->id)
        ->and($activity->published_at->equalTo($queuedAt))->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('publishes at once on the sync connection', function () {
    config()->set('queue.default', 'sync');

    Storyfeed::activity('ship', queuedPublishingDelivery())->queue();

    expect(Activity::sole()->verb)->toBe('ship');
});

it('carries models by their keys, never whole', function () {
    $delivery = queuedPublishingDelivery('SECRET-TRACKING');

    Storyfeed::activity('ship', $delivery)->queue();

    $payload = DB::table('jobs')->sole()->payload;

    expect(json_decode($payload, true)['displayName'])->toBe(PublishQueuedActivity::class)
        ->and(unserialize(json_decode($payload, true)['data']['command']))->toBeInstanceOf(PublishQueuedActivity::class)
        ->and($payload)->not->toContain('SECRET-TRACKING');
});

it('restores everything the builder was given', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $delivery = queuedPublishingDelivery();

    $pending = Storyfeed::activity('ship', $delivery)
        ->by($user)
        ->to('Warehouse')
        ->data(['note' => 'fragile'])
        ->thread(FeedThread::make(text: 'Handle with care', replies: 2))
        ->change(FeedChange::make(['status' => ['pending', 'shipped']]));

    $restored = unserialize(serialize($pending));

    expect($restored)->toBeInstanceOf(PendingActivity::class)
        ->and($restored->activity->getAttributes())->toBe($pending->activity->getAttributes())
        ->and($restored->has('actor'))->toBeTrue()
        ->and($restored->publish()->fresh()->target)->toBeInstanceOf(Party::class);

    $anonymous = unserialize(serialize(Storyfeed::activity('ship', $delivery)->anonymously()));

    expect($anonymous->isAnonymous())->toBeTrue();
});

it('goes where the call site says, over what the verb declared', function () {
    Queue::fake();
    Story::for(Delivery::class)->verb('ship')->onConnection('database')->onQueue('feed')->delay('5 seconds');

    Storyfeed::activity('ship', queuedPublishingDelivery())->queue();
    Storyfeed::activity('ship', queuedPublishingDelivery('TN-2'))->onQueue('urgent')->delay(0)->queue();

    Queue::assertPushedOn('feed', PublishQueuedActivity::class, fn (PublishQueuedActivity $job) => $job->delay === 5 && $job->connection === 'database');
    Queue::assertPushedOn('urgent', PublishQueuedActivity::class, fn (PublishQueuedActivity $job) => $job->delay === 0);
});

it('declares placement on a verb, a resource action or a bound line, and keeps it through storyfeed:cache', function () {
    Story::verb('ship')->onConnection('database')->onQueue('feed')->delay('1 minute')->afterCommit()->deleteWhenMissingModels();
    Story::verb('confirm', QueuedDispatch::class)->beforeCommit();

    Artisan::call('storyfeed:cache');
    Storyfeed::useCompiledStories(app(StoryManifest::class)->read());

    expect(Storyfeed::queueing((new Delivery)->getMorphClass(), 'ship'))->toBe([
        'connection' => 'database', 'queue' => 'feed', 'delay' => 60, 'afterCommit' => true, 'deleteWhenMissingModels' => true,
    ])
        ->and(Storyfeed::storyQueueing(QueuedDispatch::class))->toBe(['afterCommit' => false])
        ->and(Verb::make('delivery.ship')->onQueue('a')->compiledParts()['queue'])
        ->not->toBe(Verb::make('delivery.ship')->onQueue('b')->compiledParts()['queue']);
});

it('refuses a delay that is not a positive interval', function () {
    Story::verb('ship')->delay('soon');
})->throws(InvalidArgumentException::class, "->delay() on [*.ship] was given 'soon'");

it('takes Queueable\'s job middleware and chain', function () {
    Queue::fake();
    $middleware = new RateLimited('feed');

    Storyfeed::activity('ship', queuedPublishingDelivery())
        ->through([$middleware])
        ->chain([new PublishQueuedActivity(Storyfeed::activity('ping'))])
        ->queue();

    Queue::assertPushed(PublishQueuedActivity::class, fn (PublishQueuedActivity $job) => $job->middleware === [$middleware]
        && count($job->chained) === 1);
});

it('runs story middleware on the worker, not at the call', function () {
    $ran = 0;
    Story::for(Delivery::class)->verb('ship')->middleware(function (PendingActivity $activity, Closure $next) use (&$ran) {
        $ran++;

        return $next($activity->data(['by' => 'middleware']));
    });

    Storyfeed::activity('ship', queuedPublishingDelivery())->queue();

    expect($ran)->toBe(0);

    queuedPublishingWork();

    expect($ran)->toBe(1)
        ->and(Activity::sole()->data)->toBe(['by' => 'middleware']);
});

it('publishes as the Storyfeed::as() actor and the signed-in user it was queued under', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    Storyfeed::as('System', fn () => Storyfeed::activity('ship', queuedPublishingDelivery())->queue());
    $this->actingAs($user);
    Storyfeed::activity('ship', queuedPublishingDelivery('TN-2'))->queue();
    auth()->logout();

    queuedPublishingWork();
    queuedPublishingWork();

    [$scoped, $signedIn] = Activity::orderBy('id')->get()->all();

    expect($scoped->actor)->toBeInstanceOf(Party::class)
        ->and($scoped->actor->name)->toBe('System')
        ->and($signedIn->actor?->is($user))->toBeTrue();
});

it('takes the snapshots on the worker, unless snapshotNow() takes them at the call', function () {
    $later = queuedPublishingDelivery('BEFORE');
    $now = queuedPublishingDelivery('BEFORE-2');

    Storyfeed::activity('ship', $later)->queue();
    Storyfeed::activity('ship', $now)->snapshotNow()->queue();

    // Behind the model's back, so nothing but the worker could refresh a snapshot.
    Delivery::query()->whereKey($later->id)->update(['tracking_number' => 'AFTER']);
    Delivery::query()->whereKey($now->id)->update(['tracking_number' => 'AFTER-2']);

    queuedPublishingWork();
    queuedPublishingWork();

    expect(Snapshot::where('model_id', $later->id)->sole()->label)->toBe('Delivery #AFTER')
        ->and(Snapshot::where('model_id', $now->id)->sole()->label)->toBe('Delivery #BEFORE-2');
});

it('fails the job when a model is gone by the time the worker takes it', function () {
    $failed = [];
    Event::listen(JobFailed::class, function (JobFailed $event) use (&$failed) {
        $failed[] = $event->exception::class;
    });
    $delivery = queuedPublishingDelivery();

    Storyfeed::activity('ship', $delivery)->queue();
    $delivery->forceDelete();
    queuedPublishingWork();

    expect($failed)->toBe([ModelNotFoundException::class])
        ->and(Activity::count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('drops it silently with deleteWhenMissingModels, said at the call or on the verb', function (string $where) {
    $failed = 0;
    Event::listen(JobFailed::class, function () use (&$failed) {
        $failed++;
    });
    $delivery = queuedPublishingDelivery();

    if ($where === 'verb') {
        Story::for(Delivery::class)->verb('ship')->deleteWhenMissingModels();
    }

    Storyfeed::activity('ship', $delivery)
        ->when($where === 'call', fn (PendingActivity $pending) => $pending->deleteWhenMissingModels())
        ->chain([new PublishQueuedActivity(Storyfeed::activity('ping'))])
        ->queue();
    $delivery->forceDelete();
    queuedPublishingWork();

    // Its chain goes with it, as a job's does.
    expect($failed)->toBe(0)
        ->and(Activity::count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['call', 'verb']);

it('queues nothing while recording is off', function () {
    Storyfeed::withoutRecording(fn () => Storyfeed::activity('ship', queuedPublishingDelivery())->queue());

    expect(DB::table('jobs')->count())->toBe(0);
});

// ── afterCommit ───────────────────────────────────────────────────────────

it('waits for the commit to publish, and never publishes a rolled-back write', function () {
    $delivery = queuedPublishingDelivery();

    $pending = DB::transaction(function () use ($delivery) {
        $activity = Storyfeed::activity('ship', $delivery)->afterCommit()->publish();

        expect($activity->exists)->toBeFalse()
            ->and(Activity::count())->toBe(0);

        return $activity;
    });

    expect($pending->exists)->toBeTrue()
        ->and(Activity::sole()->uid)->toBe($pending->uid);

    try {
        DB::transaction(function () use ($delivery) {
            Storyfeed::activity('ship', $delivery)->afterCommit()->publish();

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(Activity::count())->toBe(1);
});

it('publishes at once outside a transaction, and inside one without afterCommit', function () {
    expect(Storyfeed::activity('ship', queuedPublishingDelivery())->afterCommit()->publish()->exists)->toBeTrue()
        ->and(DB::transaction(fn () => Storyfeed::activity('ship', queuedPublishingDelivery('TN-2'))->publish()->exists))->toBeTrue();
});

it('takes afterCommit from the verb, and beforeCommit() at the call overrides it', function () {
    Story::for(Delivery::class)->verb('ship')->afterCommit();

    DB::transaction(function () {
        Storyfeed::activity('ship', queuedPublishingDelivery())->publish();
        Storyfeed::activity('ship', queuedPublishingDelivery('TN-2'))->beforeCommit()->publish();

        expect(Activity::count())->toBe(1);
    });

    expect(Activity::count())->toBe(2);
});

it('keeps the scope\'s actor for a publish that waits for the commit', function () {
    DB::transaction(fn () => Storyfeed::as('System', fn () => Storyfeed::activity('ship', queuedPublishingDelivery())->afterCommit()->publish()));

    expect(Activity::sole()->actor?->name)->toBe('System');
});

it('queues after the commit with afterCommit(), and not at all on a rollback', function () {
    DB::transaction(function () {
        Storyfeed::activity('ship', queuedPublishingDelivery())->afterCommit()->queue();

        expect(DB::table('jobs')->count())->toBe(0);
    });

    expect(DB::table('jobs')->count())->toBe(1);

    try {
        DB::transaction(function () {
            Storyfeed::activity('ship', queuedPublishingDelivery('TN-2'))->afterCommit()->queue();

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table('jobs')->count())->toBe(1);
});

// ── A message class that implements ShouldQueue ──────────────────────────

it('queues a ShouldQueue message on publish() and returns null; publishNow() publishes it here', function () {
    Story::for(Delivery::class)->verb('dispatch', QueuedDispatch::class);
    $this->freezeSecond();
    $queuedAt = now()->toImmutable();

    expect(Storyfeed::publish(new QueuedDispatch(queuedPublishingDelivery())))->toBeNull()
        ->and(QueuedDispatch::$built)->toBe(0)
        ->and(Activity::count())->toBe(0);

    $this->travel(5)->minutes();
    queuedPublishingWork();

    expect(QueuedDispatch::$built)->toBe(1)
        ->and(Activity::sole()->verb)->toBe('dispatch')
        ->and(Activity::sole()->published_at->equalTo($queuedAt))->toBeTrue()
        ->and(Storyfeed::publishNow(new QueuedDispatch(queuedPublishingDelivery('TN-2')))?->exists)->toBeTrue();
});

it('names its job after the class', function () {
    Story::for(Delivery::class)->verb('dispatch', QueuedDispatch::class);

    Storyfeed::publish(new QueuedDispatch(queuedPublishingDelivery()));

    expect(json_decode(DB::table('jobs')->sole()->payload, true)['displayName'])->toBe(QueuedDispatch::class);
});

it('refuses to queue a message nothing registered, at the call', function () {
    Storyfeed::publish(new QueuedDispatch(queuedPublishingDelivery()));
})->throws(UnknownStory::class, 'is not registered');

it('takes the class\'s Queueable properties over the line\'s, and the line\'s over the config', function () {
    Queue::fake();
    Story::for(Delivery::class)->verb('dispatch', PlacedDispatch::class)->onQueue('feed')->delay(10);
    Story::verb('confirm', QueuedDispatch::class)->onQueue('feed')->afterCommit();

    Storyfeed::publish(new PlacedDispatch(queuedPublishingDelivery()));
    Storyfeed::publish(new QueuedDispatch(queuedPublishingDelivery('TN-2')));

    Queue::assertPushedOn('stories', PublishQueuedStory::class, fn (PublishQueuedStory $job) => $job->class === PlacedDispatch::class
        && $job->delay === 10 && $job->tries === 3 && $job->deleteWhenMissingModels);
    Queue::assertPushedOn('feed', PublishQueuedStory::class, fn (PublishQueuedStory $job) => $job->class === QueuedDispatch::class
        && $job->afterCommit === true && $job->deleteWhenMissingModels === false);
});

it('fails a message whose model is gone', function () {
    $failed = [];
    Event::listen(JobFailed::class, function (JobFailed $event) use (&$failed) {
        $failed[] = $event->exception::class;
    });
    Story::for(Delivery::class)->verb('dispatch', QueuedDispatch::class);
    $delivery = queuedPublishingDelivery();

    Storyfeed::publish(new QueuedDispatch($delivery));
    $delivery->forceDelete();
    queuedPublishingWork();

    expect($failed)->toBe([ModelNotFoundException::class])
        ->and(Activity::count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(QueuedDispatch::$built)->toBe(0);
});

it('hands the class its failure, as a queued notification is handed its', function () {
    Story::for(Delivery::class)->verb('dispatch', QueuedDispatch::class);
    QueuedDispatch::$throws = true;

    Storyfeed::publish(new QueuedDispatch(queuedPublishingDelivery()));
    queuedPublishingWork();

    expect(QueuedDispatch::$failures)->toBe([RuntimeException::class])
        ->and(Activity::count())->toBe(0);
});

it('drops a message whose model is gone when the class says deleteWhenMissingModels', function () {
    $failed = 0;
    Event::listen(JobFailed::class, function () use (&$failed) {
        $failed++;
    });
    Story::for(Delivery::class)->verb('dispatch', PlacedDispatch::class);
    $delivery = queuedPublishingDelivery();

    Storyfeed::publish(new PlacedDispatch($delivery));
    $delivery->forceDelete();
    queuedPublishingWork('stories');

    expect($failed)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('drops a second unique publish while the first is pending, and takes one once it ran', function () {
    Story::for(Delivery::class)->verb('dispatch', UniqueDispatch::class);
    $delivery = queuedPublishingDelivery();

    Storyfeed::publish(new UniqueDispatch($delivery));
    Storyfeed::publish(new UniqueDispatch($delivery));
    Storyfeed::publish(new UniqueDispatch(queuedPublishingDelivery('TN-2')));

    expect(DB::table('jobs')->count())->toBe(2);

    queuedPublishingWork();
    queuedPublishingWork();
    Storyfeed::publish(new UniqueDispatch($delivery));

    expect(Activity::count())->toBe(2)
        ->and(DB::table('jobs')->count())->toBe(1);
});

it('lets the last of several debounced publishes win', function () {
    Story::for(Delivery::class)->verb('dispatch', DebouncedDispatch::class);
    $delivery = queuedPublishingDelivery();

    Storyfeed::publish(new DebouncedDispatch($delivery, 'first'));
    Storyfeed::publish(new DebouncedDispatch($delivery, 'last'));

    expect(DB::table('jobs')->count())->toBe(2);

    $this->travel(31)->seconds();
    queuedPublishingWork();
    queuedPublishingWork();

    expect(Activity::sole()->data)->toBe(['status' => 'last']);
})->skip(! class_exists(DebounceFor::class), 'DebounceFor is Laravel 13.');

it('refuses a message that is both debounced and unique, as a job does', function () {
    Story::for(Delivery::class)->verb('dispatch', UniqueDebouncedDispatch::class);

    Storyfeed::publish(new UniqueDebouncedDispatch(queuedPublishingDelivery()));
})->throws(LogicException::class, 'A debounced job cannot also implement ShouldBeUnique.')
    ->skip(! class_exists(DebounceFor::class), 'DebounceFor is Laravel 13.');

// ── The fake ──────────────────────────────────────────────────────────────

it('records a queued publish apart from a published one, as Mail::fake() does', function () {
    Storyfeed::fake();
    Story::for(Delivery::class)->verb('dispatch', QueuedDispatch::class);
    $delivery = queuedPublishingDelivery();

    Storyfeed::activity('ship', $delivery)->queue();
    Storyfeed::publish(new QueuedDispatch($delivery));

    Storyfeed::assertQueued('ship', $delivery);
    Storyfeed::assertQueued(QueuedDispatch::class);
    Storyfeed::assertQueued('dispatch');
    Storyfeed::assertQueuedCount(2);
    Storyfeed::assertNotQueued('confirm');
    Storyfeed::assertNothingPublished();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(fn () => Storyfeed::assertPublished('ship'))
        ->toThrow(ExpectationFailedException::class, 'Did you mean to use assertQueued() instead?');
});

it('asserts nothing was queued', function () {
    Storyfeed::fake();

    Storyfeed::activity('ship', queuedPublishingDelivery())->publish();

    Storyfeed::assertNothingQueued();

    expect(fn () => Storyfeed::assertQueued('ship'))
        ->toThrow(ExpectationFailedException::class, 'Nothing was queued.');
});
