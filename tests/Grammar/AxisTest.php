<?php

use Illuminate\Support\Carbon;
use Storyfeed\Grouping\Axis;
use Storyfeed\Models\Activity;

function axisActivity(array $attributes = []): Activity
{
    return new Activity([
        'published_at' => Carbon::parse('2026-08-12 09:15:00'),
        'actor_type' => 'user', 'actor_id' => 7,
        'verb' => 'revise',
        'object_type' => 'delivery', 'object_id' => 42,
        ...$attributes,
    ]);
}

it('rejects unknown recipe tokens at registration', function () {
    expect(fn () => Axis::make('bogus')->key('aa:zz:d'))
        ->toThrow(InvalidArgumentException::class, 'zz');
});

it('does not apply when a required field is missing', function () {
    $axis = Axis::make('object')->key('aa:aid:v:oa!:oid!:d');

    expect($axis->hashFor(axisActivity()))->toBe('user:7:revise:delivery:42:2026-08-12')
        ->and($axis->hashFor(axisActivity(['object_id' => null])))->toBeNull();
});

it('derives pinned tokens from the recipe by mask algebra', function () {
    $universals = [':actors', ':objects', ':targets', ':contexts', ':count', ':others'];

    // `:verb` needs ONE field rather than an identity pair, and it joined this
    // list on 2026-08-26: the verb is in the key, so every member shares it, by
    // exactly the construction that makes :actor safe on an actor-keyed axis.
    expect(Axis::make('object')->key('aa:aid:v:oa!:oid!:d')->pinnedTokens())
        ->toBe([':actor', ':object', ':verb', ...$universals])
        ->and(Axis::make('actors')->key('v:ta!:tid:d')->pinnedTokens())
        ->toBe([':target', ':verb', ...$universals])
        // aa without aid: the actor PAIR is incomplete, so :actor is unsafe —
        // while the verb, needing no pair, is safe on the same key.
        ->and(Axis::make('half')->key('aa:v:d')->pinnedTokens())
        ->toBe([':verb', ...$universals])
        // And an axis with no verb in its key does NOT pin it.
        ->and(Axis::make('verbless')->key('aa:aid:d')->pinnedTokens())
        ->toBe([':actor', ...$universals]);
});

it('digests keys that exceed the length threshold', function () {
    $axis = Axis::make('repeat')->key('aa:aid:v:oa:ta:tid:d');

    $hash = $axis->hashFor(axisActivity([
        'verb' => str_repeat('extremely-long-verb-', 15),
    ]));

    // Silent truncation over-groups (the tackler scar); long keys become a
    // fixed-width digest instead — still derived, still recomputable.
    expect(strlen($hash))->toBe(40)
        ->and($hash)->toMatch('/^[0-9a-f]{40}$/');
});

it('supports closure recipes with manually declared pins', function () {
    $axis = Axis::make('weekly')
        ->key(fn (Activity $a) => "{$a->verb}:".$a->published_at->format('o-W'))
        ->pins(':actor');

    expect($axis->hashFor(axisActivity()))->toBe('revise:2026-33')
        ->and($axis->pinnedTokens())->toBe([':actor', ':actors', ':objects', ':targets', ':contexts', ':count', ':others']);
});

it('knows when it pins a role KIND without pinning the role', function () {
    // `repeat` carries the object ALIAS but not the object id: many objects,
    // all the same kind of thing. That gap is exactly the licence to say
    // "7 clauses" where naming one clause would be a lie.
    $repeat = Axis::make('repeat')->key('aa:aid:v:oa:ta:tid:d');

    expect($repeat->pinnedTokens())->not->toContain(':object')
        ->and($repeat->pinsType('object'))->toBeTrue()
        ->and($repeat->pinsType('actor'))->toBeTrue()
        ->and($repeat->pinsType('context'))->toBeFalse();

    // `actors` pins neither the actor's identity nor its kind — an actor can
    // be a user or a Party — so nothing may be said about the kind at all.
    $actors = Axis::make('actors')->key('v:ta!:tid:d');

    expect($actors->pinsType('actor'))->toBeFalse()
        ->and($actors->pinsType('target'))->toBeTrue()
        ->and($actors->pinsType('nonsense'))->toBeFalse();
});

it('refuses to guess a role KIND for closure and row-backed axes', function () {
    // `pins()` declares whole tokens, never the halves, so the answer is not
    // available — and guessing generously is the failure requiredRoles()
    // already refuses to commit.
    $weekly = Axis::make('weekly')->key(fn (Activity $a) => 'x')->pins(':actor');

    expect($weekly->pinsType('actor'))->toBeFalse()
        ->and(Axis::make('composite')->rowBacked()->pins(':actor')->pinsType('actor'))->toBeFalse();
});
