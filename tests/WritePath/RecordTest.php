<?php

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesAndRestoresModelIdentifiers;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedThread;
use Storyfeed\Models\Activity;
use Storyfeed\PendingActivity;
use Storyfeed\StoryfeedManager;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('records a thread through storage into the node without exposing its version', function () {
    $payload = [
        'text' => 'Thursday works.',
        'by' => 'Sally',
        'kind' => 'replied',
        'replies' => 2,
        'truncated' => true,
    ];

    $activity = Storyfeed::record(
        'confirm',
        object: Delivery::create(['tracking_number' => 'TN-1']),
        data: ['source' => 'import'],
        thread: FeedThread::make(...$payload),
    );

    expect($activity->fresh()->data)->toBe([
        'source' => 'import',
        FeedThread::KEY => [...$payload, '$v' => 1],
    ]);

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($node['thread'])->toBe($payload)
        ->and($node['thread'])->not->toHaveKey('$v')
        ->and($node['data'])->toBe(['source' => 'import']);
});

it('keeps existing positional record calls unchanged without a thread', function () {
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);
    $date = now()->subDays(3)->startOfSecond();

    $activity = Storyfeed::record('confirm', $delivery, 'Sally', 'Warehouse', 'Import', ['source' => 'import'], $date, []);

    expect($activity->fresh()->data)->toBe(['source' => 'import'])
        ->and($activity->object_id)->toEqual($delivery->id)
        ->and($activity->actor->name)->toBe('Sally')
        ->and($activity->target->name)->toBe('Warehouse')
        ->and($activity->context->name)->toBe('Import')
        ->and($activity->published_at->equalTo($date))->toBeTrue();

    $node = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($node['thread'])->toBeNull()
        ->and($node['data'])->toBe(['source' => 'import']);
});

it('accepts an explicit null thread without adding stored thread data', function () {
    $activity = Storyfeed::record(
        'confirm',
        object: Delivery::create(['tracking_number' => 'TN-1']),
        data: ['source' => 'import'],
        thread: null,
    );

    expect($activity->fresh()->data)->toBe(['source' => 'import'])
        ->and(Storyfeed::feed()->get()->toArray()['items'][0]['thread'])->toBeNull();
});

it('records an unknown actor even when another actor is available', function (string $source) {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    config(['storyfeed.parties.fallback' => 'System']);

    if ($source === 'authenticated') {
        $this->actingAs($user);
    }

    $record = fn () => Storyfeed::record('confirm', anonymous: true);
    $activity = ($source === 'scope' ? Storyfeed::actor($user, $record) : $record())->fresh();

    expect($activity->exists)->toBeTrue()
        ->and($activity->actor_type)->toBeNull()
        ->and($activity->actor_id)->toBeNull()
        ->and($activity->cached_actor_id)->toBeNull()
        ->and($activity->actor)->toBeNull();
})->with(['fallback', 'authenticated', 'scope']);

it('rejects an explicit actor combined with anonymous before publishing', function (string $kind) {
    $actor = $kind === 'model'
        ? User::create(['name' => 'Sally', 'email' => 'sally@example.com'])
        : 'Sally';

    expect(fn () => Storyfeed::record('confirm', actor: $actor, anonymous: true))
        ->toThrow(LogicException::class, 'record() was given an actor and anonymous: true; an anonymous activity has no actor.');

    expect(Activity::count())->toBe(0);
})->with(['model', 'party']);

it('keeps last-call-wins for sequential actor and anonymous builder calls', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    expect(Storyfeed::activity('confirm')->actor($user)->anonymously()->publish()->fresh()->actor)->toBeNull()
        ->and(Storyfeed::activity('confirm')->anonymously()->actor($user)->publish()->fresh()->actor->is($user))->toBeTrue();
});

it('keeps the default actor when anonymous is false', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $this->actingAs($user);

    expect(Storyfeed::record('confirm', anonymous: false)->fresh()->actor->is($user))->toBeTrue();
});

it('has a record parameter for every public builder role and setter', function () {
    $builder = new ReflectionClass(PendingActivity::class);
    $record = new ReflectionMethod(StoryfeedManager::class, 'record');
    $excluded = [
        '__construct', 'make', 'of', 'inline', // Construction, not setters.
        'publish', // The terminal.
        'verb', 'action', // The required verb argument starts the builder.
        'by', 'using', 'resulting', 'in', 'to', 'for', 'from', 'on', 'with', 'into', // Role aliases.
        'when', 'unless', // Conditional composition, not activity fields.
        'hasActor', 'isAnonymous', 'has', // What story middleware reads, not setters.
        // record() stays synchronous (ruled 2026-09-24): queueing is the
        // builder's, with Laravel's Queueable, and so is what it carries.
        'queue', 'snapshotNow', 'deleteWhenMissingModels', '__serialize', '__unserialize',
        ...array_map(fn (ReflectionMethod $m) => $m->getName(), [
            ...(new ReflectionClass(Queueable::class))->getMethods(),
            ...(new ReflectionClass(SerializesAndRestoresModelIdentifiers::class))->getMethods(),
        ]),
    ];
    $parameters = array_map(fn (ReflectionParameter $p) => $p->getName(), $record->getParameters());
    $setters = [];

    foreach ($builder->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if (in_array($method->getName(), $excluded, true)) {
            continue;
        }

        // The one deliberate spelling difference in the named-argument API.
        $setters[] = $method->getName() === 'anonymously' ? 'anonymous' : $method->getName();
    }

    $missing = array_diff($setters, $parameters);

    expect($setters)->not->toBeEmpty()
        ->and($missing)->toBe([], 'record() is missing parameters for: '.implode(', ', $missing));
});
