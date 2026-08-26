<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * TWO ACTIVITIES IN THE SAME SECOND, read three ways.
 *
 * Found by a consumer, not by us: an audit vault renamed a client twice in one
 * test, asserted the two sentences oldest-first, ran green for days under
 * `log()`, and flipped the moment they switched that feed to `live()`. Nothing
 * was nondeterministic — the grouped path's solo tiebreak ascended while
 * `logPage()`'s descended, so the pair REVERSED on a mode switch.
 *
 * It matters more than a tie usually does. Seeded and imported rows share a
 * timestamp routinely, and "which happened first" is the question an audit
 * surface exists to answer — so the answer must not depend on how the feed is
 * being read.
 */

/**
 * Two activities at one instant, carrying NO grouping rows.
 *
 * That is what the solo stream is for, and it is not a contrived state: rows
 * imported from another system, rows recorded before this package was
 * installed, and rows still waiting for the trickle all arrive exactly like
 * this. It is also where same-instant ties actually cluster, because a bulk
 * import stamps a whole batch with one timestamp.
 *
 * (An ordinary recorded activity does NOT reach this stream in a grouped mode:
 * it carries a winning grouping row, so even alone it is read as a group of
 * one. Deleting the rows is how a test reaches the branch the imported case
 * lives on.)
 */
function twoInTheSameSecond(): array
{
    $at = now();
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    $first = Storyfeed::activity()
        ->actor($user)
        ->verb('client.renamed', Delivery::create(['tracking_number' => 'FIRST']))
        ->publishedAt($at)
        ->publish();

    $second = Storyfeed::activity()
        ->actor($user)
        ->verb('client.renamed', Delivery::create(['tracking_number' => 'SECOND']))
        ->publishedAt($at)
        ->publish();

    expect($second->getKey())->toBeGreaterThan($first->getKey());

    Grouping::query()->delete();

    return [$first, $second];
}

it('puts the later row first in every read mode, when both share an instant', function () {
    [, $second] = twoInTheSameSecond();

    // The same pair, read three ways. Before the fix, log() said one thing and
    // the two grouped modes said the opposite.
    foreach (['log', 'live', 'summary'] as $mode) {
        $items = Storyfeed::feed()->{$mode}()->get()->toArray()['items'];

        expect($items)->toHaveCount(2, "mode: {$mode}")
            ->and($items[0]['id'])->toBe((string) $second->uid, "mode: {$mode}");
    }
});

it('pages a same-instant tie without repeating or skipping a row', function () {
    // The tiebreak is half of a cursor: flip the ORDER without flipping the
    // comparison and a tie pages by returning the same row forever.
    [$first, $second] = twoInTheSameSecond();

    $page = Storyfeed::feed()->live()->limit(1)->get()->toArray();

    expect($page['items'][0]['id'])->toBe((string) $second->uid)
        ->and($page['next_cursor'])->not->toBeNull();

    $next = Storyfeed::feed()->live()->limit(1)->cursor($page['next_cursor'])->get();

    expect($next->items())->toHaveCount(1)
        ->and($next->items()[0]['id'])->toBe((string) $first->uid);
});
