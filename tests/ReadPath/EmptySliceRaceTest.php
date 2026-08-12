<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * Two production 500s from the Newsroom (journal 024), one fault: a GroupSlice
 * reaching the presenter with zero hydrated members, because the read path
 * selects candidates and hydrates their members in SEPARATE queries.
 *
 * These tests must delete BETWEEN the two phases. Deleting before the feed
 * runs cannot reproduce it — both phases share one soft-delete scope, so they
 * stay consistent — which is why the straightforward before/after test passes
 * against the unfixed code.
 */

/**
 * Soft-delete the given activities the moment phase 1's group-selection
 * aggregate has run, imitating a concurrent `storyfeed:trickle` orphan sweep.
 */
function deleteAfterGroupSelection(Activity ...$activities): void
{
    $fired = false;

    DB::listen(function ($query) use (&$fired, $activities) {
        // The phase-1 aggregate is the only query carrying this projection.
        if ($fired || ! str_contains($query->sql, 'count(*) as members')) {
            return;
        }

        $fired = true;

        foreach ($activities as $activity) {
            $activity->delete();
        }
    });
}

it('drops a group whose members vanish between candidate selection and hydration', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    $members = collect(range(1, 3))->map(fn (int $i) => Storyfeed::activity()
        ->actor($user)
        ->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))
        ->publish());

    // Survives the race so the page is not merely empty-by-accident.
    Storyfeed::activity()->verb('ping')->publish();

    deleteAfterGroupSelection(...$members);

    // Before the fix: "Attempt to read property object_type on null" from
    // safeSingularFallback(), because count > 1 routes into groupNode().
    $items = Storyfeed::feed()->get()->toArray()['items'];

    expect($items)->toHaveCount(1)
        ->and($items[0]['verb'])->toBe('ping');
});

it('drops a collapsed single-member group whose member vanishes mid-flight', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    $first = Storyfeed::activity()
        ->actor($user)
        ->verb('upload', Delivery::create(['tracking_number' => 'TN-1']))
        ->publish();

    $second = Storyfeed::activity()
        ->actor($user)
        ->verb('upload', Delivery::create(['tracking_number' => 'TN-2']))
        ->publish();

    // groupStream has no HAVING floor, so a grouping whose siblings are gone
    // still arrives as a GROUP candidate — with count 1, which fails
    // isGroup() and routes into the solo branch of the presenter.
    $first->delete();

    deleteAfterGroupSelection($second);

    // Before the fix: TypeError, activityNode(): Argument #1 must be of type
    // Activity, null given.
    expect(Storyfeed::feed()->get()->toArray()['items'])->toBe([]);
});

it('still paginates when every slice on a page is dropped', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    $members = collect(range(1, 3))->map(fn (int $i) => Storyfeed::activity()
        ->actor($user)
        ->verb('upload', Delivery::create(['tracking_number' => "TN-{$i}"]))
        ->publish());

    // Enough solos to force a second page behind the group.
    foreach (range(1, 3) as $i) {
        Storyfeed::activity()->verb('ping')->publishedAt(now()->subHours($i))->publish();
    }

    deleteAfterGroupSelection(...$members);

    $page = Storyfeed::feed()->limit(1)->get()->toArray();

    // The group was page 1 in its entirety; dropping it must still hand back
    // a cursor, or the client stalls one page short of the rest of the feed.
    expect($page['items'])->toBe([])
        ->and($page['next_cursor'])->not->toBeNull();

    $next = Storyfeed::feed()->limit(1)->cursor($page['next_cursor'])->get()->toArray();

    expect($next['items'])->toHaveCount(1)
        ->and($next['items'][0]['verb'])->toBe('ping');
});
