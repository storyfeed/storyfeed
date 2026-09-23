<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\Support\MorphResolver;
use Storyfeed\Support\QueuedActor;
use Storyfeed\Tests\Queue\Fixtures\ScopedPublishJob;
use Workbench\App\Models\User;

/*
 * A job dispatched inside Storyfeed::as() runs as that actor on the worker,
 * as if it had run inside the callback.
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
});

function scopedActorRun(): void
{
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions);
}

function scopedActorOf(Activity $activity): ?string
{
    return $activity->actor_type === null ? null
        : MorphResolver::feedable($activity->actor_type, $activity->actor_id)?->getAttribute('name');
}

it('carries a Storyfeed::as() actor in the dispatch context', function () {
    $user = User::create(['name' => 'Scoped user', 'email' => 'scoped@example.test']);
    $captured = [];

    foreach (['Stripe', $user] as $actor) {
        Storyfeed::as($actor, function () use (&$captured) {
            $context = new Repository(app('events'));
            QueuedActor::capture($context);
            $captured[] = $context->getHidden(QueuedActor::KEY);
        });
    }

    expect($captured)->toBe([
        ['party' => 'Stripe', 'key' => 'stripe', 'scoped' => true],
        ['type' => 'user', 'id' => $user->id, 'scoped' => true],
    ]);
});

it('runs the job as the scoped party or model', function (string $connection, string $kind) {
    config()->set('queue.default', $connection);
    $user = User::create(['name' => 'Scoped user', 'email' => 'model@example.test']);
    $actor = $kind === 'party' ? 'Stripe' : $user;

    Storyfeed::as($actor, fn () => ScopedPublishJob::dispatch());

    if ($connection === 'database') {
        expect(Activity::count())->toBe(0);
        scopedActorRun();
    }

    expect(scopedActorOf(Activity::sole()))->toBe($kind === 'party' ? 'Stripe' : 'Scoped user');
})->with(['sync', 'database'])->with(['party', 'model']);

it('dispatches inside the scope whether the callback returns the dispatch or not', function (string $form) {
    $result = $form === 'returned'
        ? Storyfeed::as('Stripe', fn () => ScopedPublishJob::dispatch())
        : Storyfeed::as('Stripe', function () {
            ScopedPublishJob::dispatch();
        });

    scopedActorRun();

    expect($result)->toBeNull()->and(scopedActorOf(Activity::sole()))->toBe('Stripe');
})->with(['returned', 'statement']);

it('restores a party the worker has no row for, with its own key', function () {
    $party = Party::make('Platform', key: 'system');
    Storyfeed::as($party, fn () => ScopedPublishJob::dispatch());
    $party->delete();

    scopedActorRun();

    $actor = MorphResolver::feedable(Activity::sole()->actor_type, Activity::sole()->actor_id);
    expect($actor->key)->toBe('system')->and($actor->name)->toBe('Platform');
});

it('carries a party left unsaved while recording was off', function () {
    Storyfeed::stopRecording();
    Storyfeed::as('Stripe', fn () => ScopedPublishJob::dispatch());
    Storyfeed::startRecording();
    expect(Party::count())->toBe(0);

    scopedActorRun();

    expect(scopedActorOf(Activity::sole()))->toBe('Stripe');
});

it('attributes a model deleted since dispatch', function () {
    $user = User::create(['name' => 'Gone', 'email' => 'gone@example.test']);
    $id = $user->id;
    Storyfeed::as($user, fn () => ScopedPublishJob::dispatch());
    $user->delete();

    scopedActorRun();

    expect(Activity::sole()->actor_type)->toBe('user')->and(Activity::sole()->actor_id)->toBe($id);
});

it('leaves the auth-only path unchanged outside any scope', function () {
    $user = User::create(['name' => 'Request actor', 'email' => 'auth@example.test']);
    $this->actingAs($user);

    ScopedPublishJob::dispatch();

    $payload = json_decode(DB::table('jobs')->sole()->payload, true);
    expect(unserialize($payload['illuminate:log:context']['hidden'][QueuedActor::KEY]))
        ->toBe(['type' => 'user', 'id' => $user->id]);

    Auth::forgetGuards();
    scopedActorRun();

    expect(Activity::sole()->actor_id)->toBe($user->id);
});

it('prefers the scoped actor to the logged-in user', function () {
    $this->actingAs(User::create(['name' => 'Request actor', 'email' => 'both@example.test']));

    Storyfeed::as('Stripe', fn () => ScopedPublishJob::dispatch());
    Auth::forgetGuards();
    scopedActorRun();

    expect(scopedActorOf(Activity::sole()))->toBe('Stripe');
});

it('outranks an application resolver on the worker, as as() does at dispatch', function () {
    Storyfeed::as('Stripe', fn () => ScopedPublishJob::dispatch());
    Storyfeed::resolveActorUsing(fn () => Storyfeed::party('Application actor'));

    scopedActorRun();

    expect(scopedActorOf(Activity::sole()))->toBe('Stripe');
});

it('lets an explicit actor in the job win', function (string $connection) {
    config()->set('queue.default', $connection);

    Storyfeed::as('Stripe', fn () => ScopedPublishJob::dispatch(['actor' => 'Explicit']));
    $connection === 'database' && scopedActorRun();

    expect(scopedActorOf(Activity::sole()))->toBe('Explicit');
})->with(['sync', 'database']);

it('lets a Storyfeed::as() inside the job win, and restores the job scope after it', function () {
    Storyfeed::as('Stripe', fn () => ScopedPublishJob::dispatch([
        'as' => 'Inner', 'child' => ['verb' => 'child'],
    ]));

    scopedActorRun();
    scopedActorRun();

    expect(scopedActorOf(Activity::where('verb', 'queue-probe')->sole()))->toBe('Inner')
        ->and(scopedActorOf(Activity::where('verb', 'child')->sole()))->toBe('Stripe');
});

it('keeps an anonymous publish anonymous', function (string $connection) {
    config()->set('queue.default', $connection);
    config()->set('storyfeed.parties.fallback', 'Fallback');

    Storyfeed::as('Stripe', fn () => ScopedPublishJob::dispatch(['anonymous' => true]));
    $connection === 'database' && scopedActorRun();

    expect(Activity::sole()->actor_type)->toBeNull()->and(Activity::sole()->actor_id)->toBeNull();
})->with(['sync', 'database']);

it('sends nothing when there is no ambient actor, so the worker falls back as before', function () {
    ScopedPublishJob::dispatch();

    $payload = json_decode(DB::table('jobs')->sole()->payload, true);
    expect($payload['illuminate:log:context']['hidden'] ?? [])->not->toHaveKey(QueuedActor::KEY);

    config()->set('storyfeed.parties.fallback', 'Fallback');
    scopedActorRun();

    expect(scopedActorOf(Activity::sole()))->toBe('Fallback');
});

it('puts back the worker resolver after the job, including when it throws', function (bool $throws) {
    Storyfeed::resolveActorUsing(fn () => Storyfeed::party('Application actor'));
    Storyfeed::as('Stripe', fn () => ScopedPublishJob::dispatch(['throw' => $throws]));
    ScopedPublishJob::dispatch(['verb' => 'after']);

    scopedActorRun();
    scopedActorRun();

    expect(scopedActorOf(Activity::where('verb', 'queue-probe')->sole()))->toBe('Stripe')
        ->and(scopedActorOf(Activity::where('verb', 'after')->sole()))->toBe('Application actor')
        ->and(Storyfeed::scopedActor())->toBeNull();
})->with(['completes' => false, 'throws' => true]);

it('leaves nothing behind in the request after a sync job, including when it throws', function (bool $throws) {
    config()->set('queue.default', 'sync');
    $user = User::create(['name' => 'Request actor', 'email' => 'sync@example.test']);
    $this->actingAs($user);

    try {
        Storyfeed::as('Stripe', fn () => ScopedPublishJob::dispatch(['throw' => $throws]));
    } catch (RuntimeException) {
        expect($throws)->toBeTrue();
    }

    $after = Storyfeed::activity('after')->publish();

    config()->set('queue.default', 'database');
    Auth::forgetGuards();
    ScopedPublishJob::dispatch(['verb' => 'later']);
    scopedActorRun();

    expect($after->actor_id)->toBe($user->id)
        ->and(app(Repository::class)->hasHidden(QueuedActor::KEY))->toBeFalse()
        ->and(Activity::where('verb', 'later')->sole()->actor_id)->toBeNull();
})->with(['completes' => false, 'throws' => true]);

it('captures the innermost of nested scopes', function () {
    Storyfeed::as('Outer', function () {
        Storyfeed::as('Inner', fn () => ScopedPublishJob::dispatch(['verb' => 'inner']));
        ScopedPublishJob::dispatch(['verb' => 'outer']);
    });

    scopedActorRun();
    scopedActorRun();

    expect(scopedActorOf(Activity::where('verb', 'inner')->sole()))->toBe('Inner')
        ->and(scopedActorOf(Activity::where('verb', 'outer')->sole()))->toBe('Outer');
});

it('lets a hidden null opt out inside a scope too', function () {
    Context::addHidden(QueuedActor::KEY, null);

    Storyfeed::as('Stripe', fn () => ScopedPublishJob::dispatch());
    scopedActorRun();

    expect(Activity::sole()->actor_type)->toBeNull();
});
