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

    expect(Axis::make('object')->key('aa:aid:v:oa!:oid!:d')->pinnedTokens())
        ->toBe([':actor', ':object', ...$universals])
        ->and(Axis::make('actors')->key('v:ta!:tid:d')->pinnedTokens())
        ->toBe([':target', ...$universals])
        // aa without aid: the actor PAIR is incomplete, so :actor is unsafe.
        ->and(Axis::make('half')->key('aa:v:d')->pinnedTokens())
        ->toBe($universals);
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
