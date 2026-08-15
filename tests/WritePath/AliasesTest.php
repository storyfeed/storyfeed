<?php

use Storyfeed\Facades\Storyfeed;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * The role aliases are authoring sugar: each one sets exactly one role and the
 * stored row is identical whichever spelling is used. These tests assert that
 * equivalence, because the moment an alias diverges it becomes a second way to
 * record the same fact differently — the failure mode aliases exist to avoid.
 */
beforeEach(function () {
    $this->user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $this->customer = Customer::create(['name' => 'Acme Co.']);
    $this->delivery = Delivery::create([
        'customer_id' => $this->customer->id,
        'tracking_number' => 'TN-1042',
    ]);
});

it('records by() as the actor', function () {
    $activity = Storyfeed::activity()
        ->by($this->user)
        ->verb('confirm', $this->delivery)
        ->publish();

    expect($activity->actor_type)->toBe($this->user->getMorphClass())
        ->and($activity->actor_id)->toEqual($this->user->getKey());
});

it('treats by() and actor() as the same call', function () {
    $viaAlias = Storyfeed::activity()->by($this->user)->verb('confirm', $this->delivery)->publish();
    $viaRole = Storyfeed::activity()->actor($this->user)->verb('confirm', $this->delivery)->publish();

    expect($viaAlias->actor_type)->toBe($viaRole->actor_type)
        ->and($viaAlias->actor_id)->toEqual($viaRole->actor_id);
});

it('does not let by() set the ambient actor', function () {
    // Storyfeed::as() is ambient; by() is one activity. Distinct on purpose,
    // because the words are close enough to be confused.
    Storyfeed::activity()->by($this->user)->verb('confirm', $this->delivery)->publish();

    $second = Storyfeed::activity()->verb('confirm', $this->delivery)->publish();

    expect($second->actor_type)->toBeNull()
        ->and($second->actor_id)->toBeNull();
});

it('records action() as the verb, with its object', function () {
    $activity = Storyfeed::activity()
        ->by($this->user)
        ->action('confirm', $this->delivery)
        ->publish();

    expect($activity->verb)->toBe('confirm')
        ->and($activity->object_type)->toBe($this->delivery->getMorphClass())
        ->and($activity->object_id)->toEqual($this->delivery->getKey());
});

it('treats action() and verb() as the same call', function () {
    $viaAlias = Storyfeed::activity()->by($this->user)->action('confirm', $this->delivery)->publish();
    $viaRole = Storyfeed::activity()->actor($this->user)->verb('confirm', $this->delivery)->publish();

    expect($viaAlias->only(['verb', 'object_type', 'object_id', 'actor_type', 'actor_id']))
        ->toEqual($viaRole->only(['verb', 'object_type', 'object_id', 'actor_type', 'actor_id']));
});

it('accepts an enum through action(), like verb()', function () {
    $activity = Storyfeed::activity()
        ->by($this->user)
        ->action(ActivityVerb::Confirm, $this->delivery)
        ->publish();

    expect($activity->verb)->toBe('confirm');
});

dataset('target aliases', ['target', 'to', 'for', 'from', 'in', 'on', 'with', 'into']);

it('sets the target and nothing else', function (string $alias) {
    $activity = Storyfeed::activity()
        ->actor($this->user)
        ->verb('confirm', $this->delivery)
        ->{$alias}($this->customer)
        ->publish();

    expect($activity->target_type)->toBe($this->customer->getMorphClass())
        ->and($activity->target_id)->toEqual($this->customer->getKey())
        // The two roles that read as though an alias might touch them.
        ->and($activity->context_type)->toBeNull()
        ->and($activity->object_type)->toBe($this->delivery->getMorphClass());
})->with('target aliases');

it('records every target alias identically', function () {
    $rows = collect(['target', 'to', 'for', 'from', 'in', 'on', 'with', 'into'])
        ->map(fn (string $alias) => Storyfeed::activity()
            ->actor($this->user)
            ->verb('confirm', $this->delivery)
            ->{$alias}($this->customer)
            ->publish()
            ->only(['actor_type', 'actor_id', 'object_type', 'object_id', 'target_type', 'target_id', 'context_type', 'context_id']));

    expect($rows->unique()->count())->toBe(1);
});

it('keeps context out of the alias map', function () {
    // in() reads as context and is not: the aliases cover actor and target only,
    // so a context has to be asked for by name.
    $activity = Storyfeed::activity()
        ->actor($this->user)
        ->verb('confirm', $this->delivery)
        ->in($this->customer)
        ->context($this->customer)
        ->publish();

    expect($activity->target_type)->toBe($this->customer->getMorphClass())
        ->and($activity->context_type)->toBe($this->customer->getMorphClass());
});
