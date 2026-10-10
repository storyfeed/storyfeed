<?php

use Illuminate\Pagination\Cursor;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Anchor;
use Storyfeed\Grouping\Axis;
use Storyfeed\Models\Activity;
use Workbench\App\Models\User;

it('round-trips an anchor through its encoded string', function (string $axis, string $hash) {
    $anchor = Anchor::fromEncoded((new Anchor($axis, $hash))->encode());

    expect($anchor)->toBeInstanceOf(Anchor::class)
        ->and($anchor->toArray())->toBe(['axis' => $axis, 'hash' => $hash])
        ->and($anchor->parameters(['axis', 'hash']))->toBe([$axis, $hash]);
})->with([
    'recipe key' => ['object', 'user:7:revise:delivery:42:2026-08-12'],
    'burst key' => ['object', 'b1:'.str_repeat('a', 64).':01J1K2M3N4P5Q6R7S8T9V0W1X2'],
    'digest' => ['inbox', sha1('sally@example.com')],
    'separator in the hash' => ['composite', "a\x1fb"],
]);

it('reads every version it has emitted', function (string $encoded, array $parameters) {
    // Ids already in payloads and app storage: decoding them may never break.
    expect(Anchor::fromEncoded($encoded)?->toArray())->toBe($parameters);
})->with([
    'v2' => ['grp_djIfcmVwZWF0H3Bpbmc', ['axis' => 'repeat', 'hash' => 'ping']],
]);

it('encodes today as v2', function () {
    expect((new Anchor('repeat', 'ping'))->encode())->toBe('grp_djIfcmVwZWF0H3Bpbmc');
});

it('reads a string that is not a group id as null', function (mixed $encoded) {
    expect(Anchor::fromEncoded($encoded))->toBeNull();
})->with([
    'null' => [null],
    'integer' => [42],
    'empty' => [''],
    'prefix only' => ['grp_'],
    'not base64' => ['grp_!!'],
    'v1 digest' => ['grp_'.sha1('legacy')],
    'activity uid' => ['01J1K2M3N4P5Q6R7S8T9V0W1X2'],
    'laravel cursor' => [(new Cursor(['id' => 5]))->encode()],
    'unknown version' => ['grp_'.base64_encode("v9\x1frepeat\x1fping")],
    'no hash' => ['grp_'.base64_encode("v2\x1frepeat\x1f")],
    'no axis' => ['grp_'.base64_encode("v2\x1f\x1fping")],
    'no separator' => ['grp_'.base64_encode('v2')],
]);

it('refuses a parameter it does not carry', function () {
    (new Anchor('repeat', 'ping'))->parameter('window');
})->throws(UnexpectedValueException::class, 'Unable to find parameter [window]');

it('never carries a closure axis key in a group id', function () {
    Storyfeed::axes([
        Axis::make('inbox')->key(fn (Activity $activity) => User::find($activity->actor_id)?->email)->fallback(),
    ], merge: false);
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->actor($sally)->verb('ping')->publishedAt(now()->subSeconds(4 - $i))->publish();
    }

    $node = Storyfeed::feed()->get()->toArray()[0];
    $decoded = base64_decode(strtr(substr($node['id'], 4), '-_', '+/'));

    expect($node['kind'])->toBe('group')
        ->and($node['count'])->toBe(3)
        ->and($decoded)->not->toContain('sally')
        ->and(Anchor::fromEncoded($node['id'])?->parameter('hash'))->toBe(sha1('sally@example.com'));
});

it('reads members from an anchor or its encoded string', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 5) as $i) {
        Storyfeed::activity()->actor($sally)->verb('ping')->publishedAt(now()->subSeconds(6 - $i))->publish();
    }

    $id = Storyfeed::feed()->get()->toArray()[0]['id'];
    $members = fn (Anchor|string $group) => collect(Storyfeed::feed()->members($group, 10)->items())->pluck('id')->all();

    expect($members(Anchor::fromEncoded($id)))->toHaveCount(5)
        ->toBe($members($id));
});
