<?php

use Illuminate\Support\Carbon;
use Storyfeed\Models\Activity;

/* Live's logical recipes changed by the 2026-10-07 ruling. Persisted burst
 * identity and migration stability are covered separately in LiveBurstsTest. */

function strategyHashes(array $attributes): array
{
    $strategy = app(config('storyfeed.grouping.strategy'));

    $hashes = $strategy->hashes(new Activity([
        'published_at' => Carbon::parse('2026-08-12 09:15:00'),
        ...$attributes,
    ]));

    // The hash STRINGS are the contract (they live in feed_groupings rows);
    // the array's key order never was — it is registration order now.
    ksort($hashes);

    return $hashes;
}

it('produces the frozen hashes for a fully-roled activity', function () {
    $hashes = strategyHashes([
        'actor_type' => 'user', 'actor_id' => 7,
        'verb' => 'revise',
        'object_type' => 'delivery', 'object_id' => 42,
        'target_type' => 'customer', 'target_id' => 9,
    ]);

    expect($hashes)->toBe([
        'actors' => 'revise:delivery:42:customer:9::',
        // L3 adds a target social candidate; all existing hashes stay stable.
        // No object identity/type: the shared thing is the target.
        'actors_target' => 'revise:customer:9::',
        'object' => 'user:7:revise:delivery:42:customer:9::',
        'repeat' => 'user:7:revise:delivery:customer:9::',
        'targets' => 'user:7:revise::',
    ]);
});

it('produces the frozen hashes for an anonymous, untargeted activity', function () {
    $hashes = strategyHashes([
        'verb' => 'revise',
        'object_type' => 'delivery', 'object_id' => 42,
    ]);

    // No actor means no targets candidate. The object still admits actors;
    // missing optional target/context fields are empty strings.
    expect($hashes)->toBe([
        'actors' => 'revise:delivery:42::::',
        'object' => '::revise:delivery:42::::',
        'repeat' => '::revise:delivery::::',
    ]);
});

it('produces the frozen hashes for an objectless activity', function () {
    $hashes = strategyHashes([
        'actor_type' => 'user', 'actor_id' => 7,
        'verb' => 'ping',
    ]);

    expect($hashes)->toBe([
        'repeat' => 'user:7:ping:::::',
        'targets' => 'user:7:ping::',
    ]); // ksorted
});
