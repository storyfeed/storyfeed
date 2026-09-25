<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Context\Repository;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Party;
use Storyfeed\Support\QueuedContext;
use Storyfeed\Tests\Queue\Fixtures\ScopedContextJob;
use Workbench\App\Models\User;

beforeEach(function () {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', 'testing');
    config()->set('queue.failed.driver', 'null');
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

function scopedContextRun(): void
{
    app('queue.worker')->runNextJob('database', 'default', new WorkerOptions);
}

it('seeds a builder without opening a scope', function () {
    $pending = Storyfeed::context('Project');
    expect($pending->verb('seeded')->publish()->context?->key)->toBe('project')
        ->and(Storyfeed::activity('outside')->publish()->context_id)->toBeNull();
});

it('applies context at publish with explicit context winning and nested scopes restored', function () {
    $pending = Storyfeed::activity('before');
    $result = Storyfeed::context('Outer', function () use ($pending) {
        $pending->publish();
        Storyfeed::context('Inner', function () {
            Storyfeed::activity('inner')->publish();
            Storyfeed::activity('explicit')->context('Explicit')->publish();
        });

        return Storyfeed::activity('outer')->publish();
    });
    expect($result->context?->key)->toBe('outer')
        ->and(Activity::where('verb', 'before')->sole()->context?->key)->toBe('outer')
        ->and(Activity::where('verb', 'inner')->sole()->context?->key)->toBe('inner')
        ->and(Activity::where('verb', 'explicit')->sole()->context?->key)->toBe('explicit')
        ->and(Storyfeed::activity('outside')->publish()->context_id)->toBeNull();
});

it('carries scalar context identity and runs the job in its scope', function (string $connection, string $kind) {
    config()->set('queue.default', $connection);
    $model = User::create(['name' => 'Context', 'email' => 'context@example.test']);
    $context = $kind === 'party' ? Party::make('Project', key: 'custom-project') : $model;
    Storyfeed::context($context, fn () => ScopedContextJob::dispatch());
    if ($connection === 'database') {
        $payload = json_decode(DB::table('jobs')->sole()->payload, true);
        expect(unserialize($payload['illuminate:log:context']['hidden'][QueuedContext::KEY]))
            ->toBe($kind === 'party' ? ['party' => 'Project', 'key' => 'custom-project'] : ['type' => 'user', 'id' => $model->id]);
        $context->delete();
        scopedContextRun();
    }
    expect(Activity::sole()->context_type)->toBe($context->getMorphClass());
    if ($kind === 'party') {
        expect(Activity::sole()->context->key)->toBe('custom-project');
    } else {
        expect(Activity::sole()->context_id)->toEqual($context->getKey());
    }
})->with(['sync', 'database'])->with(['party', 'model']);

it('captures nested scopes and carries the job scope to a child', function () {
    Storyfeed::context('Outer', function () {
        Storyfeed::context('Inner', fn () => ScopedContextJob::dispatch(['verb' => 'inner']));
        ScopedContextJob::dispatch(['verb' => 'outer', 'child' => ['verb' => 'child']]);
    });
    scopedContextRun();
    scopedContextRun();
    scopedContextRun();
    expect(Activity::where('verb', 'inner')->sole()->context?->key)->toBe('inner')
        ->and(Activity::where('verb', 'outer')->sole()->context?->key)->toBe('outer')
        ->and(Activity::where('verb', 'child')->sole()->context?->key)->toBe('outer');
});

it('lets an explicit job context and inner job scope win', function (string $connection) {
    config()->set('queue.default', $connection);
    Storyfeed::context('Outer', fn () => ScopedContextJob::dispatch(['context' => 'Explicit', 'scope' => 'Inner']));
    $connection === 'database' && scopedContextRun();
    expect(Activity::sole()->context?->key)->toBe('explicit');
})->with(['sync', 'database']);

it('restores context after successful and failed jobs without leaking into subsequent dispatch', function (string $connection, bool $throws) {
    config()->set('queue.default', $connection);
    try {
        Storyfeed::context('Project', fn () => ScopedContextJob::dispatch(['throw' => $throws]));
    } catch (RuntimeException) {
        expect($throws)->toBeTrue();
    }
    $connection === 'database' && scopedContextRun();
    expect(Storyfeed::activity('outside')->publish()->context_id)->toBeNull()
        ->and(app(Repository::class)->hasHidden(QueuedContext::KEY))->toBeFalse();
    ScopedContextJob::dispatch(['verb' => 'after']);
    $connection === 'database' && scopedContextRun();
    expect(Activity::where('verb', 'probe')->sole()->context?->key)->toBe('project')
        ->and(Activity::where('verb', 'after')->sole()->context_id)->toBeNull();
})->with(['sync', 'database'])->with([false, true]);

it('restores an outer scope after an inner callback throws', function () {
    Storyfeed::context('Outer', function () {
        expect(fn () => Storyfeed::context('Inner', function () {
            throw new RuntimeException('failed');
        }))->toThrow(RuntimeException::class, 'failed');
        expect(Storyfeed::activity('after')->publish()->context?->key)->toBe('outer');
    });
    expect(Storyfeed::activity('outside')->publish()->context_id)->toBeNull();
});

it('restores the job context after its own scope closes before dispatching a child', function () {
    Storyfeed::context('Outer', fn () => ScopedContextJob::dispatch([
        'scope' => 'Inner', 'child' => ['verb' => 'child'],
    ]));
    scopedContextRun();
    scopedContextRun();
    expect(Activity::where('verb', 'probe')->sole()->context?->key)->toBe('inner')
        ->and(Activity::where('verb', 'child')->sole()->context?->key)->toBe('outer');
});

it('carries a party created while recording is disabled', function () {
    Storyfeed::stopRecording();
    Storyfeed::context('Project', fn () => ScopedContextJob::dispatch());
    expect(Party::count())->toBe(0);
    Storyfeed::startRecording();
    scopedContextRun();
    expect(Activity::sole()->context?->key)->toBe('project');
});

it('applies context to fake publishes too', function () {
    Storyfeed::fake();
    Storyfeed::context('Project', fn () => Storyfeed::activity('probe')->publish());
    Storyfeed::assertPublished(fn (Activity $activity) => $activity->verb === 'probe' && $activity->context_type === 'storyfeed.party');
});
