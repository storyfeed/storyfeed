<?php

use Illuminate\Support\Carbon;
use Storyfeed\Models\Activity;

/*
 * FROZEN HASH STRINGS — the gate for any grouping-strategy refactor.
 *
 * These literals are what the pre-Axis implementation produced. Deployed
 * feed_groupings rows contain exactly these strings; a refactor that
 * changes any byte silently orphans every existing cluster. If this test
 * fails: fix the recipe resolution — NEVER adjust the frozen strings.
 */

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
        'actors' => 'revise:customer:9:2026-08-12',
        'object' => 'user:7:revise:delivery:42:2026-08-12',
        'repeat' => 'user:7:revise:delivery:customer:9:2026-08-12',
        'targets' => 'user:7:revise:2026-08-12',
    ]);
});

it('produces the frozen hashes for an anonymous, untargeted activity', function () {
    $hashes = strategyHashes([
        'verb' => 'revise',
        'object_type' => 'delivery', 'object_id' => 42,
    ]);

    // No actor: targets absent. No target: actors absent. Nulls are ''.
    expect($hashes)->toBe([
        'object' => '::revise:delivery:42:2026-08-12',
        'repeat' => '::revise:delivery:::2026-08-12',
    ]);
});

it('produces the frozen hashes for an objectless activity', function () {
    $hashes = strategyHashes([
        'actor_type' => 'user', 'actor_id' => 7,
        'verb' => 'ping',
    ]);

    expect($hashes)->toBe([
        'repeat' => 'user:7:ping::::2026-08-12',
        'targets' => 'user:7:ping:2026-08-12',
    ]); // ksorted
});
