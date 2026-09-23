<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Exceptions\UndeclaredParty;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Support\IgnoredParties;
use Storyfeed\Support\MorphResolver;
use Storyfeed\Support\QueuedActor;
use Storyfeed\Tests\Fixtures\Stories\CarriedStory;
use Storyfeed\Tests\Fixtures\Stories\RefundStory;
use Storyfeed\Tests\Queue\Fixtures\CarriedPublishJob;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * An action that takes the request chooses its actor at each publish. On a
 * queue worker the request is blank, so what it chose at dispatch travels
 * with the job, as its result: never the request, never a model.
 */

beforeEach(function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', 'testing');
    config()->set('queue.failed.driver', 'null');
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

    RefundStory::$seen = [];
    CarriedStory::$runs = [];
    Story::resource(Delivery::class, RefundStory::class)->only('refund');
    Story::resource(Delivery::class, CarriedStory::class)->only('ship', 'jam');
    Storyfeed::compileStories();

    $this->delivery = Delivery::create(['tracking_number' => 'T1']);
});

/** The request a controller sees, as the HTTP kernel binds it. */
function carriedRequest(array $input): void
{
    app()->instance('request', Request::create('/refunds', 'POST', $input));
}

/** The worker's side: a blank request and no one signed in. */
function carriedRun(): void
{
    app()->instance('request', Request::create('/'));
    Auth::forgetGuards();
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions);
}

function carriedPayload(): mixed
{
    $hidden = json_decode(DB::table('jobs')->sole()->payload, true)['illuminate:log:context']['hidden'] ?? [];

    return isset($hidden[QueuedActor::ACTIONS]) ? unserialize($hidden[QueuedActor::ACTIONS]) : null;
}

function carriedActorOf(Activity $activity): ?string
{
    return $activity->actor_type === null ? null
        : MorphResolver::feedable($activity->actor_type, $activity->actor_id)?->getAttribute('name');
}

it('carries the party an action chose, as a name', function () {
    carriedRequest(['provider' => 'Stripe']);

    CarriedPublishJob::dispatch($this->delivery->id);

    expect(carriedPayload())->toBe(['delivery.refund' => ['party' => 'Stripe']]);
});

it('runs the job as the party the action chose at dispatch', function (string $connection) {
    config()->set('queue.default', $connection);
    carriedRequest(['provider' => 'Stripe']);

    CarriedPublishJob::dispatch($this->delivery->id);
    $connection === 'database' && carriedRun();

    expect(carriedActorOf(Activity::sole()))->toBe('Stripe');
})->with(['sync', 'database']);

it('carries a model by morph alias and key, and runs the job as it', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@example.com']);
    carriedRequest(['shipper' => $sam->id]);

    CarriedPublishJob::dispatch($this->delivery->id, 'ship');

    expect(carriedPayload())->toBe(['delivery.ship' => ['type' => 'user', 'id' => $sam->id]]);

    carriedRun();

    expect(carriedActorOf(Activity::sole()))->toBe('Sam');
});

it('attributes a model deleted since dispatch', function () {
    $sam = User::create(['name' => 'Sam', 'email' => 'sam@example.com']);
    carriedRequest(['shipper' => $sam->id]);
    CarriedPublishJob::dispatch($this->delivery->id, 'ship');
    $sam->delete();

    carriedRun();

    expect(Activity::sole()->actor_type)->toBe('user')->and(Activity::sole()->actor_id)->toBe($sam->id);
});

it('lets an explicit actor in the job win', function (string $connection) {
    config()->set('queue.default', $connection);
    carriedRequest(['provider' => 'Stripe']);

    CarriedPublishJob::dispatch($this->delivery->id, input: ['actor' => 'Explicit']);
    $connection === 'database' && carriedRun();

    expect(carriedActorOf(Activity::sole()))->toBe('Explicit');
})->with(['sync', 'database']);

it('keeps an anonymous publish anonymous', function (string $connection) {
    config()->set('queue.default', $connection);
    config()->set('storyfeed.parties.fallback', 'Fallback');
    carriedRequest(['provider' => 'Stripe']);

    CarriedPublishJob::dispatch($this->delivery->id, input: ['anonymous' => true]);
    $connection === 'database' && carriedRun();

    expect(Activity::sole()->actor_type)->toBeNull()->and(Activity::sole()->actor_id)->toBeNull();
})->with(['sync', 'database']);

it('carries no opinion as nothing, so the worker falls through as the request would', function () {
    $ines = User::create(['name' => 'Ines', 'email' => 'ines@example.com']);
    $this->actingAs($ines);
    carriedRequest([]);

    CarriedPublishJob::dispatch($this->delivery->id);

    // Evaluated, and chose nothing: no key, never a null actor.
    expect(carriedPayload())->toBe([]);

    carriedRun();

    expect(Activity::sole()->actor_id)->toBe($ines->id);
});

it('ignores an undeclared name on the worker, as it would in the request', function () {
    config()->set('storyfeed.parties.strict', false);
    Storyfeed::parties(['Paddle']);
    config()->set('storyfeed.parties.fallback', 'Fallback');
    carriedRequest(['provider' => 'Stripe']);

    CarriedPublishJob::dispatch($this->delivery->id);
    carriedRun();

    expect(carriedActorOf(Activity::sole()))->toBe('Fallback')
        ->and(IgnoredParties::all())->toBe(['Stripe']);
});

it('throws for an undeclared name on the worker in strict mode, as it would in the request', function () {
    Exceptions::fake();
    config()->set('storyfeed.parties.strict', true);
    Storyfeed::parties(['Paddle']);
    carriedRequest(['provider' => 'Stripe']);

    // The dispatch doesn't guard: the publish does.
    CarriedPublishJob::dispatch($this->delivery->id);
    carriedRun();

    Exceptions::assertReported(fn (UndeclaredParty $e) => str_contains($e->getMessage(), 'Stripe'));
    expect(Activity::count())->toBe(0);
});

it('never fails a dispatch for an action that throws, and keeps it for the doctor', function () {
    Exceptions::fake();
    config()->set('storyfeed.parties.fallback', 'Fallback');
    carriedRequest(['provider' => 'Stripe', 'jam' => 1]);

    CarriedPublishJob::dispatch($this->delivery->id, 'jam');
    CarriedPublishJob::dispatch($this->delivery->id, 'jam');

    expect(DB::table('jobs')->count())->toBe(2);
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === 'the printer jammed');
    Exceptions::assertReportedCount(1);

    carriedRun();

    $finding = collect(Storyfeed::doctor(['actions'])->findings)->firstWhere('code', 'actions.carry_failed');

    expect(carriedActorOf(Activity::sole()))->toBe('Fallback')
        ->and($finding->subject)->toBe(['action' => CarriedStory::class.'@jam'])
        ->and($finding->message)->toContain('the printer jammed');
});

it('runs each action once per request, however many jobs it dispatches', function () {
    carriedRequest(['provider' => 'Stripe']);

    CarriedPublishJob::dispatch($this->delivery->id);
    CarriedPublishJob::dispatch($this->delivery->id);
    CarriedPublishJob::dispatch($this->delivery->id);

    // Once when stories compiled, once for the three dispatches.
    expect(RefundStory::$seen)->toBe([null, 'Stripe'])
        ->and(CarriedStory::$runs)->toBe(['ship' => 2, 'jam' => 2]);
});

it('runs nothing and carries nothing when no job is dispatched', function () {
    carriedRequest(['provider' => 'Stripe']);

    Storyfeed::activity('refund', $this->delivery)->publish();

    expect(RefundStory::$seen)->toBe([null, 'Stripe'])
        ->and(CarriedStory::$runs)->toBe(['ship' => 1, 'jam' => 1])
        ->and(app(Repository::class)->hasHidden(QueuedActor::ACTIONS))->toBeFalse();
});

it('evaluates nothing inside Storyfeed::as(), which outranks every action', function () {
    carriedRequest(['provider' => 'Stripe']);

    Storyfeed::as('Scoped', fn () => CarriedPublishJob::dispatch($this->delivery->id));

    expect(carriedPayload())->toBeNull()->and(RefundStory::$seen)->toBe([null]);

    carriedRun();

    expect(carriedActorOf(Activity::sole()))->toBe('Scoped');
});

it('keeps what a parent carried for a job it dispatches on the worker', function () {
    carriedRequest(['provider' => 'Stripe']);

    CarriedPublishJob::dispatch($this->delivery->id, input: ['child' => 'refund']);
    carriedRun();
    carriedRun();

    expect(Activity::count())->toBe(2)
        ->and(Activity::all()->map(carriedActorOf(...))->all())->toBe(['Stripe', 'Stripe']);
});

it('leaves nothing behind in the request after a sync job', function () {
    config()->set('queue.default', 'sync');
    carriedRequest(['provider' => 'Stripe']);

    CarriedPublishJob::dispatch($this->delivery->id);

    carriedRequest(['provider' => 'Paddle']);
    $after = Storyfeed::activity('refund', $this->delivery)->publish();

    expect(app(Repository::class)->hasHidden(QueuedActor::ACTIONS))->toBeFalse()
        ->and($after->actor->name)->toBe('Paddle');
});

it('warns about an action that reads the request without taking it', function () {
    Story::resource(Delivery::class, CarriedStory::class)->only('peek');
    Storyfeed::compileStories();

    $findings = collect(Storyfeed::doctor(['actions'])->findings)->where('code', 'actions.request_helper');

    expect($findings->pluck('subject')->all())->toBe([['action' => CarriedStory::class.'@peek']]);
});
