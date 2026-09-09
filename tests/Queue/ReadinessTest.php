<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\TrickleSnapshots;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Events\Snapshots\ActivitySnapshot;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Batch;
use Storyfeed\Models\Grouping;
use Storyfeed\Support\MorphResolver;
use Storyfeed\Support\QueuedActor;
use Storyfeed\Tests\Events\Fixtures\QueuedActivityListener;
use Storyfeed\Tests\Queue\Fixtures\PublishListener;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

beforeEach(function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', 'testing');
    config()->set('storyfeed.parties.fallback', null);
    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });
    PublishListener::$seen = [];
    PublishListener::$rendered = [];
    QueuedActivityListener::$seen = [];
    Event::listen('queue-readiness', PublishListener::class);
});

function queueReadinessPublish(array $input): void
{
    Event::dispatch('queue-readiness', [$input]);
}

function queueReadinessRun(): void
{
    // Real serialized CallQueuedListener, restored by Laravel's database driver.
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions);
}

it('preserves the request actor after authentication is gone', function () {
    $user = User::create(['name' => 'Request actor', 'email' => 'queue@example.test']);
    $delivery = Delivery::create(['tracking_number' => 'QUEUE']);
    $this->actingAs($user);
    queueReadinessPublish(['delivery' => $delivery->id]);
    expect(Activity::count())->toBe(0)->and(DB::table('jobs')->count())->toBe(1);
    Auth::forgetGuards();
    expect(Auth::user())->toBeNull();
    queueReadinessRun();
    $activity = Activity::sole();
    expect(PublishListener::$seen)->toBe([['auth' => null, 'id' => $activity->id]])
        ->and($activity->actor_type)->toBe($user->getMorphClass())->and($activity->actor_id)->toBe($user->id)
        ->and(Batch::count())->toBe(1)->and(DB::table('jobs')->count())->toBe(0);
});

it('distinguishes explicit Party fallback and deliberate anonymity on a worker', function (string $mode) {
    $delivery = Delivery::create(['tracking_number' => 'QUEUE']);
    $input = ['delivery' => $delivery->id];
    if ($mode === 'explicit') {
        $input['actor'] = 'Nightly Import';
    } else {
        config()->set('storyfeed.parties.fallback', 'Nightly Import');
    }
    if ($mode === 'anonymous') {
        $input['anonymous'] = true;
    }
    queueReadinessPublish($input);
    queueReadinessRun();
    $activity = Activity::sole();
    expect(Auth::user())->toBeNull();
    if ($mode === 'anonymous') {
        expect($activity->actor_type)->toBeNull()->and(Batch::count())->toBe(0);
    } else {
        expect(MorphResolver::feedable($activity->actor_type, $activity->actor_id)->name)->toBe('Nightly Import')
            ->and(Batch::count())->toBe(1);
    }
})->with(['explicit', 'fallback', 'anonymous']);

it('demonstrates midnight grouping and job-time entity snapshots', function () {
    $this->travelTo(now()->startOfDay()->setTime(23, 55));
    $occurredAt = now()->toIso8601String();
    $delivery = Delivery::create(['tracking_number' => 'BEFORE']);
    $original = Storyfeed::activity('queue-probe', $delivery)->actor('Importer')->publish();
    queueReadinessPublish(['delivery' => $delivery->id, 'actor' => 'Importer']);
    queueReadinessPublish(['delivery' => $delivery->id, 'actor' => 'Importer', 'occurred_at' => $occurredAt]);
    $this->travel(10)->minutes();
    $delivery->update(['tracking_number' => 'AFTER']);
    queueReadinessRun();
    queueReadinessRun();
    $late = Activity::findOrFail(PublishListener::$seen[0]['id']);
    $dated = Activity::findOrFail(PublishListener::$seen[1]['id']);
    $hash = fn ($row) => Grouping::where('activity_id', $row->id)->where('bucket', 'repeat')->value('hash');
    expect($late->published_at->format('H:i'))->toBe('00:05')
        ->and($dated->published_at->format('H:i'))->toBe('23:55')
        ->and($hash($late))->not->toBe($hash($original))
        ->and($hash($dated))->toBe($hash($original))
        ->and($dated->cachedObject->label)->toBe('Delivery #AFTER');
});

it('round trips immutable facts across trickle pruning with no live domain rows', function () {
    Event::listen(ActivityPublished::class, QueuedActivityListener::class);
    $delivery = Delivery::create(['tracking_number' => 'FROZEN']);
    $activity = Storyfeed::activity('queue-probe', $delivery)->actor('Importer')->publish();
    $expected = unserialize(serialize(new ActivityPublished(ActivitySnapshot::fromModel($activity))))->activity->toPayload();
    DB::table('deliveries')->where('id', $delivery->id)->delete();
    DB::table('feed_snapshots')->where('model_type', 'delivery')->delete();
    $activity->forceFill(['cached_object_id' => null])->saveQuietly();
    (new TrickleSnapshots)(prune: true);
    expect($activity->fresh()->trashed())->toBeTrue();
    queueReadinessRun();
    expect(QueuedActivityListener::$seen)->toBe([$expected]);
});

it('queues only after outer commit and drops a partial publish on rollback', function () {
    Event::listen(ActivityPublished::class, QueuedActivityListener::class);
    DB::beginTransaction();
    Storyfeed::activity('queue-probe')->publish();
    expect(DB::table('jobs')->count())->toBe(0);
    DB::rollBack();
    expect(Activity::count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
    DB::beginTransaction();
    Storyfeed::activity('queue-probe')->publish();
    expect(DB::table('jobs')->count())->toBe(0);
    DB::commit();
    expect(DB::table('jobs')->count())->toBe(1);
    queueReadinessRun();
    expect(QueuedActivityListener::$seen)->toHaveCount(1);
});

it('does not confuse Queue fake acceptance with a job having published', function () {
    $delivery = Delivery::create(['tracking_number' => 'FAKE']);
    Storyfeed::fake();
    Queue::fake();
    queueReadinessPublish(['delivery' => $delivery->id]);
    Queue::assertPushed(CallQueuedListener::class);
    Storyfeed::assertNothingPublished();
    $queued = Queue::pushed(CallQueuedListener::class)->first();
    app($queued->class)->{$queued->method}(...$queued->data);
    Storyfeed::assertPublishedCount(1);
    expect(Activity::count())->toBe(0);
});

it('renders feedMedia in the queued consumer without authenticating an HTTP request', function () {
    $delivery = Delivery::create(['tracking_number' => 'MEDIA']);
    $calls = Delivery::$feedMediaCalls;
    queueReadinessPublish(['delivery' => $delivery->id, 'actor' => 'Importer', 'render' => true]);
    queueReadinessRun();
    expect(Auth::user())->toBeNull()
        ->and(Delivery::$feedMediaCalls)->toBeGreaterThan($calls)
        ->and(PublishListener::$rendered['items'][0]['object']['url'])->toBe('/deliveries/'.$delivery->id);
});

it('keeps explicit actors and anonymity ahead of transported identity and fallback', function (bool $anonymous) {
    $user = User::create(['name' => 'Request actor', 'email' => 'precedence@example.test']);
    $delivery = Delivery::create(['tracking_number' => 'PRECEDENCE']);
    $this->actingAs($user);
    config()->set('storyfeed.parties.fallback', 'Fallback');
    queueReadinessPublish(['delivery' => $delivery->id, 'actor' => 'Explicit', 'anonymous' => $anonymous]);
    $payload = json_decode(DB::table('jobs')->sole()->payload, true);
    $key = QueuedActor::KEY;
    expect(unserialize($payload['illuminate:log:context']['hidden'][$key]))
        ->toBe(['type' => 'user', 'id' => $user->id])
        ->and($payload['illuminate:log:context']['data'])->not->toHaveKey($key);
    Auth::forgetGuards();
    queueReadinessRun();
    $activity = Activity::sole();
    if ($anonymous) {
        expect($activity->actor_type)->toBeNull()->and($activity->actor_id)->toBeNull();
    } else {
        expect($activity->actor->name)->toBe('Explicit');
    }
})->with([false, true]);

it('does not leak the first job actor into a second job in the same worker', function () {
    $user = User::create(['name' => 'First actor', 'email' => 'first@example.test']);
    $delivery = Delivery::create(['tracking_number' => 'ISOLATED']);
    $this->actingAs($user);
    queueReadinessPublish(['delivery' => $delivery->id]);
    Auth::forgetGuards();
    // Both payloads are created before the worker runs: the second has no actor.
    queueReadinessPublish(['delivery' => $delivery->id]);
    $worker = app('queue.worker');
    queueReadinessRun();
    expect(Activity::sole()->actor_id)->toBe($user->id);
    queueReadinessRun();
    expect(app('queue.worker'))->toBe($worker)
        ->and(Activity::orderBy('id')->get()->last()->actor_id)->toBeNull()
        ->and(app(Repository::class)->hasHidden(QueuedActor::KEY))->toBeFalse()
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('preserves a deleted actor identity without serializing a model', function () {
    $user = User::create(['name' => 'Deleted actor', 'email' => 'deleted@example.test']);
    $id = $user->id;
    $delivery = Delivery::create(['tracking_number' => 'DELETED']);
    $this->actingAs($user);
    queueReadinessPublish(['delivery' => $delivery->id]);
    Auth::forgetGuards();
    $user->delete();
    queueReadinessRun();
    expect(Activity::sole()->actor_type)->toBe('user')->and(Activity::sole()->actor_id)->toBe($id);
});

it('allows an app to suppress automatic transport with hidden null', function () {
    $user = User::create(['name' => 'Request actor', 'email' => 'optout@example.test']);
    $delivery = Delivery::create(['tracking_number' => 'OPT-OUT']);
    $this->actingAs($user);
    Context::addHidden(QueuedActor::KEY, null);
    queueReadinessPublish(['delivery' => $delivery->id]);
    Auth::forgetGuards();
    queueReadinessRun();
    expect(Activity::sole()->actor_type)->toBeNull()->and(Activity::sole()->actor_id)->toBeNull();
});

it('records an unknown actor on a fresh worker without request context', function () {
    $delivery = Delivery::create(['tracking_number' => 'FRESH']);
    queueReadinessPublish(['delivery' => $delivery->id]);
    queueReadinessRun();
    expect(Activity::sole()->actor_type)->toBeNull()->and(Activity::sole()->actor_id)->toBeNull();
});

it('uses transported identity before a worker fallback', function () {
    $user = User::create(['name' => 'Request actor', 'email' => 'fallback@example.test']);
    $delivery = Delivery::create(['tracking_number' => 'FALLBACK']);
    $this->actingAs($user);
    queueReadinessPublish(['delivery' => $delivery->id]);
    Auth::forgetGuards();
    config()->set('storyfeed.parties.fallback', 'Fallback');
    queueReadinessRun();
    expect(Activity::sole()->actor_type)->toBe('user')->and(Activity::sole()->actor_id)->toBe($user->id);
});

it('retains application resolver authority on the worker', function () {
    $user = User::create(['name' => 'Request actor', 'email' => 'resolver@example.test']);
    $delivery = Delivery::create(['tracking_number' => 'RESOLVER']);
    $this->actingAs($user);
    queueReadinessPublish(['delivery' => $delivery->id]);
    Auth::forgetGuards();
    Storyfeed::resolveActorUsing(fn () => Storyfeed::party('Application actor'));
    queueReadinessRun();
    expect(Activity::sole()->actor->name)->toBe('Application actor');
});
