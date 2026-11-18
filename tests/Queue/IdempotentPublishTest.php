<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Stories\StoryManifest;
use Storyfeed\Tests\Queue\Fixtures\QueuedDispatch;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * An idempotent publish treats a duplicate on an app's unique index as done,
 * so a retried job finishes instead of landing in failed_jobs (#261).
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
    IdempotentFailures::$count = 0;
    Event::listen(JobFailed::class, fn () => IdempotentFailures::$count++);

    // An app's own index: one activity per verb and object.
    Schema::table(config('storyfeed.tables.activities', 'feed_activities'), function (Blueprint $table) {
        $table->unique(['verb', 'object_type', 'object_id'], 'idempotent_test_unique');
    });
});

afterEach(function () {
    app(StoryManifest::class)->delete();

    Schema::table(config('storyfeed.tables.activities', 'feed_activities'), function (Blueprint $table) {
        $table->dropUnique('idempotent_test_unique');
    });
});

final class IdempotentFailures
{
    public static int $count = 0;
}

function idempotentWork(): void
{
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions(maxTries: 1));
}

it('still throws on a duplicate without the option', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::activity('ship', $delivery)->publish();

    expect(fn () => Storyfeed::activity('ship', $delivery)->publish())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('fails a queued duplicate without the option', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::activity('ship', $delivery)->publish();

    Storyfeed::activity('ship', $delivery)->queue();
    idempotentWork();

    expect(IdempotentFailures::$count)->toBe(1);
});

it('returns the unsaved activity for a duplicate with idempotent()', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::activity('ship', $delivery)->publish();

    $second = Storyfeed::activity('ship', $delivery)->idempotent()->publish();

    expect($second->exists)->toBeFalse()
        ->and(Activity::count())->toBe(1);
});

it('takes idempotent as a record() argument', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::record('ship', $delivery);

    expect(Storyfeed::record('ship', $delivery, idempotent: true)->exists)->toBeFalse()
        ->and(fn () => Storyfeed::record('ship', $delivery))->toThrow(UniqueConstraintViolationException::class);
});

it('leaves the caller\'s transaction usable after a duplicate', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::activity('ship', $delivery)->publish();

    DB::transaction(function () use ($delivery) {
        Storyfeed::activity('ship', $delivery)->idempotent()->publish();
        Storyfeed::activity('deliver', $delivery)->publish();
    });

    expect(Activity::pluck('verb')->sort()->values()->all())->toBe(['deliver', 'ship']);
});

it('treats a duplicate as done after the commit', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::activity('ship', $delivery)->publish();

    DB::transaction(fn () => Storyfeed::activity('ship', $delivery)->idempotent()->afterCommit()->publish());

    expect(Activity::count())->toBe(1);
});

it('lets a queued duplicate finish with idempotent()', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::activity('ship', $delivery)->publish();

    Storyfeed::activity('ship', $delivery)->idempotent()->queue();
    idempotentWork();

    expect(IdempotentFailures::$count)->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(Activity::count())->toBe(1);
});

it('takes idempotent from the verb, and idempotent(false) at the call overrides it', function () {
    Story::for(Delivery::class)->verb('ship')->idempotent();
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    Storyfeed::activity('ship', $delivery)->publish();

    expect(Storyfeed::queueing($delivery->getMorphClass(), 'ship'))->toBe(['idempotent' => true])
        ->and(Storyfeed::activity('ship', $delivery)->publish()->exists)->toBeFalse();

    Storyfeed::activity('ship', $delivery)->queue();
    idempotentWork();

    expect(IdempotentFailures::$count)->toBe(0)
        ->and(fn () => Storyfeed::activity('ship', $delivery)->idempotent(false)->publish())
        ->toThrow(UniqueConstraintViolationException::class);
});

it('lets a queued message class finish on a duplicate when its line is idempotent', function () {
    Story::verb('confirm', QueuedDispatch::class)->idempotent();
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::publish(new QueuedDispatch($delivery));
    Storyfeed::publish(new QueuedDispatch($delivery));
    idempotentWork();
    idempotentWork();

    expect(IdempotentFailures::$count)->toBe(0)
        ->and(Activity::count())->toBe(1);
});

it('records an idempotent publish on the fake as any other', function () {
    Storyfeed::fake();
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    Storyfeed::activity('ship', $delivery)->idempotent()->publish();
    Storyfeed::activity('ship', $delivery)->idempotent()->queue();

    Storyfeed::assertPublished('ship');
    Storyfeed::assertQueued('ship');
});

it('carries the scoped actor, context and signed-in user past an afterCommit dispatch', function () {
    $user = User::create(['name' => 'Signed in', 'email' => 'signed@example.test']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    DB::transaction(function () use ($delivery) {
        Storyfeed::actor('Stripe', fn () => Storyfeed::context('Project', fn () => Storyfeed::activity('ship', $delivery)->afterCommit()->queue()));
    });

    DB::transaction(function () use ($delivery, $user) {
        Auth::login($user);
        Storyfeed::activity('deliver', $delivery)->afterCommit()->queue();
        Auth::logout();
    });

    expect(DB::table('jobs')->count())->toBe(2);

    idempotentWork();
    idempotentWork();

    $scoped = Activity::where('verb', 'ship')->sole();
    $signed = Activity::where('verb', 'deliver')->sole();

    expect($scoped->actor?->name)->toBe('Stripe')
        ->and($scoped->context?->key)->toBe('project')
        ->and($signed->actor_id)->toEqual($user->id)
        ->and($signed->actor_type)->toBe($user->getMorphClass());
});
