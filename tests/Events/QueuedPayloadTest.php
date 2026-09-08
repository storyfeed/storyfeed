<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Actions\CloseBatches;
use Storyfeed\Events\ActivityDeleted;
use Storyfeed\Events\ActivityPublished;
use Storyfeed\Events\BatchClosed;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
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

it('keeps models and private attributes off the queue while retaining feed labels', function () {
    Event::listen(ActivityPublished::class, QueuedActivityListener::class);
    [$activity] = publishConfirmation();
    expect(DB::table('jobs')->count())->toBe(1);
    $command = (string) json_decode((string) DB::table('jobs')->value('payload'))->data->command;
    expect($command)->toContain($activity->uid)
        ->not->toContain('Storyfeed\\Models\\Activity')
        ->not->toContain('Workbench\\App\\Models\\User')
        ->not->toContain('sally@example.com')
        ->not->toContain('rt-secret-token')
        ->toContain('TN-1');
});

it('delivers the published facts after the activity and entities change or disappear', function () {
    Event::listen(ActivityPublished::class, QueuedActivityListener::class);
    [$activity, $user, $delivery] = publishConfirmation();
    $delivery->update(['tracking_number' => 'CHANGED']);
    $activity->forceDelete();
    $job = app('queue')->connection('database')->pop();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $job->fire();
    // Database queue acknowledgement reads/deletes its own job; no domain query is allowed.
    $queries = array_values(array_filter(DB::getQueryLog(),
        fn ($query) => ! in_array($query['query'], [
            'select * from "jobs" where "id" = ? limit 1',
            'delete from "jobs" where "id" = ?',
        ], true)));
    DB::disableQueryLog();
    expect(QueuedActivityListener::$seen[0]['object']['label'])->toBe('Delivery #TN-1')
        ->and(QueuedActivityListener::$seen[0]['uid'])->toBe($activity->uid)
        ->and($queries)->toBe([]);
});

it('reads the same plain facts synchronously without queries', function () {
    $seen = null;
    $queries = null;
    Event::listen(ActivityPublished::class, function (ActivityPublished $event) use (&$seen, &$queries) {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $seen = $event->activity->toArray();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
    });
    publishConfirmation();
    expect($seen['actor']['label'])->toBe('Sally')
        ->and($seen['object']['label'])->toBe('Delivery #TN-1')
        ->and($seen['target']['label'])->toBe('Acme Co.')
        ->and($queries)->toBe([]);
});

it('queues deletion facts even after a force delete removes the row', function (bool $force) {
    Event::listen(ActivityDeleted::class, QueuedActivityListener::class);
    [$activity] = publishConfirmation();
    $force ? $activity->forceDelete() : $activity->delete();
    $job = app('queue')->connection('database')->pop();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $job->fire();
    // Database queue acknowledgement reads/deletes its own job; no domain query is allowed.
    $queries = array_values(array_filter(DB::getQueryLog(),
        fn ($query) => ! in_array($query['query'], [
            'select * from "jobs" where "id" = ? limit 1',
            'delete from "jobs" where "id" = ?',
        ], true)));
    DB::disableQueryLog();
    expect(QueuedActivityListener::$seen[0]['uid'])->toBe($activity->uid)
        ->and(QueuedActivityListener::$seen[0]['forceDeleted'])->toBe($force)
        ->and(QueuedActivityListener::$seen[0]['object']['label'])->toBe('Delivery #TN-1')
        ->and($queries)->toBe([]);
})->with([true, false]);

it('queues batch members before bundling and retains them after deletion', function () {
    Event::listen(BatchClosed::class, QueuedActivityListener::class);
    [$activity] = publishConfirmation();
    $this->travel(11)->minutes();
    (new CloseBatches)();
    $activity->forceDelete();
    Batch::query()->delete();
    $job = app('queue')->connection('database')->pop();
    DB::enableQueryLog();
    DB::flushQueryLog();
    $job->fire();
    // Database queue acknowledgement reads/deletes its own job; no domain query is allowed.
    $queries = array_values(array_filter(DB::getQueryLog(),
        fn ($query) => ! in_array($query['query'], [
            'select * from "jobs" where "id" = ? limit 1',
            'delete from "jobs" where "id" = ?',
        ], true)));
    DB::disableQueryLog();
    expect(QueuedActivityListener::$seen[0]['activities'][0]['uid'])->toBe($activity->uid)
        ->and(QueuedActivityListener::$seen[0]['activities'][0]['object']['label'])->toBe('Delivery #TN-1')
        ->and($queries)->toBe([]);
});

it('rejects old model constructors loudly', function () {
    [$activity] = publishConfirmation();
    expect(fn () => new ActivityPublished($activity))->toThrow(TypeError::class)
        ->and(fn () => new ActivityDeleted($activity))->toThrow(TypeError::class)
        ->and(fn () => new BatchClosed(Batch::firstOrFail()))->toThrow(TypeError::class);
});

it('still invokes old listeners and errors at their model typed boundary', function () {
    Event::listen(ActivityPublished::class, function (ActivityPublished $event) {
        (function (Activity $activity) {})($event->activity);
    });
    expect(fn () => publishConfirmation())->toThrow(TypeError::class);
});

it('freezes before the outer commit and rejects nested mutation', function () {
    $seen = null;
    Event::listen(ActivityPublished::class, function (ActivityPublished $event) use (&$seen) {
        $seen = $event;
    });
    DB::transaction(function () {
        [$activity] = publishConfirmation();
        $activity->verb = 'changed';
        $activity->save();
    });
    $copy = unserialize(serialize($seen));
    expect($seen->activity->verb)->toBe('confirm')
        ->and($copy->activity->toPayload())->toBe($seen->activity->toArray());
    expect(function () use ($seen) {
        $seen->activity->object['label'] = 'changed';
    })->toThrow(Error::class);
    expect(function () use ($seen) {
        $seen->activity = $seen->activity;
    })->toThrow(Error::class);
});

it('delivers all three AS2 role facts through the real queue without live models', function () {
    Event::listen(ActivityPublished::class, QueuedActivityListener::class);
    $tool = User::create(['name' => 'Tablet operator', 'email' => 'private@example.com', 'remember_token' => 'w78-private-token']);
    $activity = Storyfeed::activity('confirm')->origin('Warehouse')->using($tool)->resulting('Receipt')->publish();
    $command = (string) json_decode((string) DB::table('jobs')->value('payload'))->data->command;
    expect($command)->not->toContain('w78-private-token', 'private@example.com', 'Workbench\\App\\Models\\User');
    $tool->delete();
    $activity->forceDelete();
    app('queue')->connection('database')->pop()->fire();
    expect(QueuedActivityListener::$seen[0]['origin']['label'])->toBe('Warehouse')
        ->and(QueuedActivityListener::$seen[0]['instrument']['label'])->toBe('Tablet operator')
        ->and(QueuedActivityListener::$seen[0]['result']['label'])->toBe('Receipt');
});
