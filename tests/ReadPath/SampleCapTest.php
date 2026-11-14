<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

/*
 * A FEED THAT DRAWS NO SAMPLES NEED NOT CARRY THEM (#247, 2026-11-14).
 *
 * A dashboard card drew a group's headline and count, and carried about
 * 17 KB of sample members it never showed. `samples(n)` caps every sample
 * list per feed or per read. Group-node shape is contract, so a list capped
 * to nothing is an empty list, never a missing key, and the counts, the
 * headline and the pinned roles are read before the cut.
 */

/** Five reviewers on one customer: one group pinning the object. */
function sampleCapFeed(): void
{
    $customer = Customer::create(['name' => 'Acme']);

    foreach (range(1, 5) as $i) {
        $user = User::create(['name' => "Reviewer {$i}", 'email' => "cap{$i}@example.test"]);
        Storyfeed::activity('review', $customer)->actor($user)->publishedAt(now()->subMinutes(10 - $i))->publish();
    }
}

/** @param  array<string, mixed>  $payload */
function sampleCapGroup(array $payload): array
{
    $group = collect($payload['data'])->firstWhere('kind', 'group');
    expect($group)->not->toBeNull();

    return $group;
}

it('sends empty sample lists and keeps every count, name and headline', function () {
    sampleCapFeed();

    $full = Storyfeed::feed()->cursorPaginate(20)->toArray();
    $none = Storyfeed::feed()->samples(0)->cursorPaginate(20)->toArray();
    $before = sampleCapGroup($full);
    $after = sampleCapGroup($none);

    expect($before['sample']['actors'])->toHaveCount(3)
        ->and(array_keys($after['sample']))->toBe(array_keys($before['sample']));

    foreach ($after['sample'] as $entities) {
        expect($entities)->toBe([]);
    }

    // Only the lists change: the object the group pins is still named.
    unset($before['sample'], $after['sample']);
    expect($after)->toBe($before)
        ->and($after['object'])->not->toBeNull()
        ->and($after['distinct']['actors'])->toBe(5)
        ->and(json_encode($none))->toContain('"actors":[]')
        ->and(strlen((string) json_encode($none)))->toBeLessThan(strlen((string) json_encode($full)));
});

it('caps each list at the given number', function () {
    sampleCapFeed();

    $group = sampleCapGroup(Storyfeed::feed()->samples(1)->cursorPaginate(20)->toArray());

    expect($group['sample']['actors'])->toHaveCount(1)
        ->and($group['sample']['objects'])->toHaveCount(1)
        ->and($group['distinct']['actors'])->toBe(5);
});

it('reads the cap from a named feed and from get()', function () {
    sampleCapFeed();
    Storyfeed::feeds(['card' => fn (FeedBuilder $feed) => $feed->samples(0)]);

    $paged = sampleCapGroup(Storyfeed::feed('card')->cursorPaginate(20)->toArray());
    $got = collect(Storyfeed::feed('card')->get()->toArray())->firstWhere('kind', 'group');

    expect($paged['sample']['actors'])->toBe([])
        ->and($got['sample']['actors'])->toBe([])
        ->and($got['count'])->toBe(5);
});

it('rejects a negative cap', function () {
    Storyfeed::feed()->samples(-1);
})->throws(InvalidArgumentException::class, 'samples() takes 0 or more');
