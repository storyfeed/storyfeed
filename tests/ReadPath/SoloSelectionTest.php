<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/**
 * FeedBuilder::soloStream() selects "activities with no WINNING grouping row"
 * by negating each disjunct of winning() separately rather than negating
 * winning() as a whole — a W103 performance rewrite (2026-09-09) that cut the
 * query from 263ms to 109ms on MySQL at 50k by keeping every probe on a
 * covering index.
 *
 * The two forms are equivalent only because of an argument (see notSolo()),
 * and an argument is exactly the kind of thing that rots. These tests pin the
 * BEHAVIOUR the argument claims, one grouping-row shape at a time, so that a
 * future disjunct added to winning() without its mirror in notSolo() fails
 * here — loudly — instead of silently dropping activities from the feed, or
 * silently showing a grouped activity twice.
 *
 * Rows are written directly rather than curated into place: the point is to
 * cover shapes curation may never produce today (imports, legacy data, a
 * half-run trickle), because the read path must degrade gracefully on all of
 * them. Each shape gets TWO activities sharing one hash, so a grouped shape
 * reaches the payload as a real group node — a lone member is not a group,
 * which would otherwise hide the very difference these tests exist to see.
 */
function shaped(string $name, array $rows): array
{
    $uids = [];

    foreach (['a', 'b'] as $suffix) {
        $activity = Storyfeed::activity()
            ->actor(User::create(['name' => $name.$suffix, 'email' => "{$name}-{$suffix}@example.com"]))
            ->verb('upload', Delivery::create(['tracking_number' => "{$name}-{$suffix}"]))
            ->for(Customer::firstOrCreate(['name' => 'Solo Project']))
            ->publish();

        Grouping::query()->where('activity_id', $activity->getKey())->delete();

        foreach ($rows as [$bucket, $winner]) {
            Grouping::query()->create([
                'activity_id' => $activity->getKey(),
                'bucket' => $bucket,
                // Shared across both activities: this is what makes them one
                // group rather than two groups of one.
                'hash' => "{$name}:{$bucket}",
                'winner' => $winner,
            ]);
        }

        $uids[] = $activity->uid;
    }

    return $uids;
}

/** The uids the read surfaces as SOLO activity nodes, ungrouped. */
function soloUids(string $mode = 'summary'): array
{
    return collect(Storyfeed::feed()->{$mode}()->get()->toArray()['items'])
        ->where('kind', 'activity')
        ->pluck('id')
        ->all();
}

it('treats activities with no grouping rows at all as solo', function () {
    $uids = shaped('none', []);

    expect(soloUids())->toEqualCanonicalizing($uids);
});

it('does not treat an activity with a stamped winner as solo', function () {
    shaped('winner', [['actors', true]]);

    expect(soloUids())->toBeEmpty();
});

it('does not treat an uncurated repeat row as solo, because repeat is the fallback', function () {
    // No winner stamped ANYWHERE for these activities, so `repeat` wins by
    // fallback. This is the case the dropped nested subquery used to decide,
    // and the one that breaks first if the rewrite is wrong.
    shaped('fallback', [['repeat', null]]);

    expect(soloUids())->toBeEmpty();
});

it('treats a non-repeat row with no winner stamped as solo', function () {
    // An `actors` candidate curation never stamped, with no repeat row beside
    // it: nothing here wins, so the activities must still reach the reader
    // rather than vanish.
    $uids = shaped('unstamped', [['actors', null]]);

    expect(soloUids())->toEqualCanonicalizing($uids);
});

it('does not treat a losing candidate as solo while a winner sits beside it', function () {
    shaped('beside', [['actors', true], ['repeat', false]]);

    expect(soloUids())->toBeEmpty();
});

it('treats a stamped loss with no winner beside it as solo', function () {
    // `winner = false` is a stamped LOSS, not a win, and there is no repeat
    // row to fall back to. Both forms must read false as "not winning" — on a
    // nullable boolean, `= true` and `is not null` disagree here.
    $uids = shaped('loser', [['actors', false]]);

    expect(soloUids())->toEqualCanonicalizing($uids);
});

it('does not treat a winning composite as solo', function () {
    shaped('composite', [['composite', true]]);

    expect(soloUids())->toBeEmpty()
        ->and(soloUids('live'))->toBeEmpty();
});

it('reads an actors winner as solo in live mode and grouped in summary', function () {
    // live() has its own winning() branch (repeat, plus declared composites),
    // so it has its own mirror in notSolo(). An `actors` row is invisible to
    // live mode, which makes these activities solo THERE and grouped here.
    $uids = shaped('live', [['actors', true]]);

    expect(soloUids('live'))->toEqualCanonicalizing($uids)
        ->and(soloUids())->toBeEmpty();
});

it('does not treat a repeat row as solo in live mode', function () {
    shaped('liverepeat', [['repeat', null]]);

    expect(soloUids('live'))->toBeEmpty();
});
