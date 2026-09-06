<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Events\ActivityDeleted;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Events\BatchClosed;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Batch;
use Storyfeed\Tests\Events\Fixtures\QueuedActivityListener;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * What a queued listener's job carries, versus what a synchronous listener
 * is handed. The Activity from publish() has the actor, object and target
 * loaded; $hidden governs toArray(), not serialize(), so left alone the
 * job would carry the actor's remember_token into the jobs table.
 */

beforeEach(function () {
    // A real database queue on the suite's connection, so the payload is the
    // one a worker would read — not Queue::fake(), which never serializes.
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', 'testing');

    Schema::create('jobs', function (Blueprint $table) {
        $table->bigIncrements('id');
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    // The workbench User has no remember_token column; the point is a
    // hidden attribute on the actor, and Authenticatable hides this one.
    Schema::table('users', fn (Blueprint $table) => $table->rememberToken());

    QueuedActivityListener::$seen = [];
});

function publishConfirmation(): array
{
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com', 'remember_token' => 'rt-secret-token']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $customer = Customer::create(['name' => 'Acme Co.']);

    $activity = Storyfeed::activity('confirm', $delivery)->actor($user)->for($customer)->publish();

    return [$activity, $user, $delivery];
}

it('keeps the relation graph and the actor\'s hidden attributes off the queue', function () {
    Event::listen(ActivityPublished::class, QueuedActivityListener::class);

    [$activity] = publishConfirmation();

    expect(DB::table('jobs')->count())->toBe(1);

    // The serialized CallQueuedListener inside the JSON envelope.
    $command = (string) json_decode((string) DB::table('jobs')->value('payload'))->data->command;

    expect($command)
        ->toContain('Storyfeed\Models\Activity')
        ->toContain($activity->uid)
        ->not->toContain('Workbench\App\Models\User')
        ->not->toContain('Workbench\App\Models\Delivery')
        ->not->toContain('Workbench\App\Models\Customer')
        ->not->toContain('sally@example.com')
        ->not->toContain('rt-secret-token')
        ->not->toContain('TN-1')
        // The measured 6,760 bytes before; an Activity alone is under half.
        ->and(strlen($command))->toBeLessThan(3500);
});

it('hands the worker a detached copy that can still reach its object', function () {
    Event::listen(ActivityPublished::class, QueuedActivityListener::class);

    [$activity] = publishConfirmation();

    app('queue')->connection('database')->pop()->fire();

    expect(QueuedActivityListener::$seen)->toBe([[
        'uid' => $activity->uid,
        'exists' => true,
        'relations' => [],
        'object_tracking_number' => 'TN-1',
    ]]);
});

it('hands a synchronous listener the loaded graph, at no query', function () {
    // A naive withoutRelations() on the model handed to the event would
    // pass the test above and turn every sync listener's ->object into a
    // lazy load. The relations must survive the in-process dispatch.
    $queries = null;
    $email = null;

    Event::listen(ActivityPublished::class, function (ActivityPublished $event) use (&$queries, &$email) {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $email = $event->activity->actor->email;
        $tracking = $event->activity->object->tracking_number;
        $target = $event->activity->target->name;

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();
    });

    publishConfirmation();

    expect($email)->toBe('sally@example.com')
        ->and($queries)->toBe(0);
});

it('serializes a copy and leaves the in-memory event\'s relations intact', function () {
    [$activity] = publishConfirmation();

    $event = new ActivityPublished($activity);

    $copy = unserialize(serialize($event));

    expect($event->activity->getRelations())->toHaveKeys(['actor', 'object', 'target'])
        ->and($copy->activity->getRelations())->toBe([])
        ->and($copy->activity->is($activity))->toBeTrue()
        ->and($copy->activity->uid)->toBe($activity->uid);
});

it('does the same for ActivityDeleted and BatchClosed', function () {
    [$activity, $user] = publishConfirmation();

    $activity->delete();

    $deleted = unserialize(serialize(new ActivityDeleted($activity)));

    expect($deleted->activity->getRelations())->toBe([])
        ->and($deleted->activity->trashed())->toBeTrue()
        ->and(serialize(new ActivityDeleted($activity)))->not->toContain('rt-secret-token');

    $batch = Batch::query()->firstOrFail()->load('activities');

    $closed = unserialize(serialize(new BatchClosed($batch)));

    expect($batch->relationLoaded('activities'))->toBeTrue()
        ->and($closed->batch->getRelations())->toBe([])
        ->and($closed->batch->is($batch))->toBeTrue();
});
