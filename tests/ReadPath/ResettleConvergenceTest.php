<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The threshold-crossing sweep in CurateCluster::resettle() must converge —
 * a member decided for a lower-priority axis is upgraded the moment a
 * higher-priority cluster it belongs to becomes eligible — AND must stop:
 * once a cluster has settled, an axis that is eligible but loses on priority
 * selects nothing. The second property was false for months (W108, Solo todo
 * 887): `winner = false` was read as "never decided", so every eligible loser
 * re-settled its whole cluster on every publish. Convergence is asserted
 * first, because getting the predicate wrong in the other direction — a
 * cluster that needs re-deciding and never is — renders fine on a stale axis
 * and is invisible.
 */

function resettleUpload(User $user, Customer $project): Activity
{
    static $n = 0;

    return Storyfeed::activity('upload')
        ->actor($user)
        ->object(Delivery::create(['tracking_number' => 'r-'.(++$n)]))
        ->target($project)
        ->publish();
}

function resettleUser(string $name): User
{
    return User::firstOrCreate(['email' => strtolower($name).'@example.test'], ['name' => $name]);
}

/** @return array<string, bool|null> bucket => winner, for one activity */
function resettleStamps(Activity $activity): array
{
    return Grouping::query()->where('activity_id', $activity->id)
        ->whereNotIn('bucket', ['composite', 'batch'])
        ->orderBy('bucket')->pluck('winner', 'bucket')
        ->map(fn ($w) => $w === null ? null : (bool) $w)->all();
}

it('upgrades a member from a winning lower axis when a higher-priority cluster becomes eligible', function () {
    $bob = resettleUser('Bob');
    [$p1, $p2, $p3] = collect(['P1', 'P2', 'P3'])->map(fn ($n) => Customer::create(['name' => $n]))->all();

    // Bob uploads to three projects: his `targets` cluster has 3 members and
    // 3 distinct targets, so it is eligible and wins — no `actors` cluster
    // has enough actors yet.
    $bobToP1 = resettleUpload($bob, $p1);
    resettleUpload($bob, $p2);
    resettleUpload($bob, $p3);

    expect(resettleStamps($bobToP1))->toBe(['actors' => false, 'object' => false, 'repeat' => false, 'targets' => true]);

    // Two more actors upload to P1. P1's `actors` cluster now has three
    // distinct actors: eligible, and higher priority than `targets`. Bob's
    // P1 upload is stamped `targets` — a real, earned winner — and must be
    // upgraded anyway. It was never "undecided"; it was decided for a loser.
    resettleUpload(resettleUser('Sally'), $p1);
    resettleUpload(resettleUser('Ann'), $p1);

    expect(resettleStamps($bobToP1))->toBe(['actors' => true, 'object' => false, 'repeat' => false, 'targets' => false]);

    $items = collect(Storyfeed::feed()->get()->toArray()['items']);

    // P1 is now an actors group of three. Bob's P2 and P3 uploads stay on
    // his targets cluster: membership is by hash, so the cluster still has
    // three members and three distinct targets and is still eligible — only
    // Bob's P1 upload was outranked.
    expect($items->firstWhere('axis', 'actors')['count'])->toBe(3)
        ->and($items->firstWhere('axis', 'targets')['count'])->toBe(2)
        ->and(Grouping::query()->where('winner', true)->count())->toBe(Activity::count());
});

it('upgrades every member of a repeat-stamped cluster when the actors threshold is crossed', function () {
    $project = Customer::create(['name' => 'Concur']);
    $bob = resettleUser('Bob');

    $first = resettleUpload($bob, $project);
    resettleUpload($bob, $project);

    expect(resettleStamps($first)['repeat'])->toBeTrue()
        ->and(resettleStamps($first)['actors'])->toBeFalse();

    resettleUpload(resettleUser('Sally'), $project);
    $tipping = resettleUpload(resettleUser('Ann'), $project);

    expect(resettleStamps($first))->toBe(['actors' => true, 'object' => false, 'repeat' => false, 'targets' => false])
        ->and(resettleStamps($tipping)['actors'])->toBeTrue()
        ->and(Grouping::query()->where('bucket', 'actors')->where('winner', true)->count())->toBe(4);
});

it('converges to the same stamps as a full re-curation, so the sweep never leaves a member behind', function () {
    // A denser shape, where actors wins and targets is an eligible loser on
    // every member: the sweep's stamps must equal what deciding every
    // activity from scratch produces.
    $users = collect(['A1', 'A2', 'A3', 'A4'])->map(fn ($n) => resettleUser($n));
    $projects = collect(['T1', 'T2', 'T3'])->map(fn ($n) => Customer::create(['name' => $n]));

    foreach (range(1, 2) as $round) {
        foreach ($users as $user) {
            foreach ($projects as $project) {
                resettleUpload($user, $project);
            }
        }
    }

    $inline = Grouping::query()->orderBy('id')->get(['activity_id', 'bucket', 'winner'])->toArray();

    Grouping::query()->update(['winner' => null]);
    $this->artisan('storyfeed:curate')->assertSuccessful();

    expect(Grouping::query()->orderBy('id')->get(['activity_id', 'bucket', 'winner'])->toArray())->toBe($inline)
        ->and(Grouping::query()->where('bucket', 'actors')->where('winner', true)->count())->toBe(24);
});

it('still downgrades survivors after a delete, which is the non-monotone path the sweep does not own', function () {
    $project = Customer::create(['name' => 'Concur']);
    $ann = resettleUser('Ann');

    resettleUpload(resettleUser('Bob'), $project);
    resettleUpload(resettleUser('Sally'), $project);
    $annUpload = resettleUpload($ann, $project);

    expect(Grouping::query()->where('bucket', 'actors')->where('winner', true)->count())->toBe(3);

    $annUpload->forceDelete();

    // The survivors are stamped `actors => true` on a cluster that no longer
    // earns it. resettle()'s staleness predicate would never select them (a
    // winner on the highest axis looks settled) — afterDelete must not go
    // through it.
    expect(Grouping::query()->where('bucket', 'actors')->where('winner', true)->count())->toBe(0)
        ->and(Grouping::query()->where('bucket', 'repeat')->where('winner', true)->count())->toBe(2);
});
