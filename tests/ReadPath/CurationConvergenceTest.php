<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

function curationRaceCluster(): Customer
{
    $target = Customer::create(['name' => 'Project']);

    foreach (['Ann', 'Bob', 'Cid'] as $name) {
        Storyfeed::activity('upload')
            ->actor(User::create(['name' => $name, 'email' => strtolower($name).'@example.test']))
            ->object(Delivery::create(['tracking_number' => $name]))
            ->target($target)->publish();
    }

    return $target;
}

function stampCurationRaceOutcome(int $mask): void
{
    // Enumerate committed outcomes from stale (repeat) and current (actors)
    // decisions. This models the race's stored outcomes, not database isolation
    // or actual concurrent workers. Candidate hashes and activities stay real.
    foreach (Activity::query()->orderBy('id')->get() as $index => $activity) {
        $winner = ($mask & (1 << $index)) !== 0 ? 'actors' : 'repeat';
        Grouping::query()->where('activity_id', $activity->id)->where('bucket', '!=', 'batch')
            ->update(['winner' => false]);
        Grouping::query()->where('activity_id', $activity->id)->where('bucket', $winner)
            ->update(['winner' => true]);
    }
}

function curationPayloadMemberIds(): array
{
    return collect(Storyfeed::feed()->summary()->get()->toArray()['items'])
        ->flatMap(fn ($node) => $node['kind'] === 'group' ? array_column($node['children'], 'id') : [$node['id']])
        ->sort()->values()->all();
}

it('converges after writers stop to one canonical winner per activity without losing members', function (int $mask, string $repair) {
    $target = curationRaceCluster();
    $ids = Activity::query()->pluck('uid')->sort()->values()->all();
    stampCurationRaceOutcome($mask);

    // Even mixed stale/current decisions must preserve every activity exactly once.
    expect(curationPayloadMemberIds())->toBe($ids);

    if ($repair === 'publish') {
        Storyfeed::activity('upload')->actor('Dee')
            ->object(Delivery::create(['tracking_number' => 'Dee']))->target($target)->publish();
    } else {
        $this->artisan('storyfeed:curate')->assertSuccessful();
    }

    $activities = Activity::query()->get();
    $winners = Grouping::query()->where('winner', true)->get();
    $node = Storyfeed::feed()->summary()->get()->toArray()['items'];

    expect($winners->count())->toBe($activities->count())
        ->and($winners->pluck('activity_id')->unique()->count())->toBe($activities->count())
        ->and($winners->pluck('bucket')->unique()->all())->toBe(['actors'])
        ->and($node)->toHaveCount(1)
        ->and($node[0]['axis'])->toBe('actors')
        ->and($node[0]['count'])->toBe($activities->count())
        ->and(curationPayloadMemberIds())->toBe($activities->pluck('uid')->sort()->values()->all());

    $settled = Grouping::query()->orderBy('id')->get(['activity_id', 'bucket', 'hash', 'winner'])->toArray();
    $this->artisan('storyfeed:curate')->assertSuccessful();
    expect(Grouping::query()->orderBy('id')->get(['activity_id', 'bucket', 'hash', 'winner'])->toArray())->toBe($settled);
})->with(range(0, 7))->with(['publish', 'command']);

it('does not heal on reads or elapsed time and needs a repair that includes the stale historical cluster', function () {
    curationRaceCluster();
    stampCurationRaceOutcome(0);
    $ids = curationPayloadMemberIds();

    $this->travel(30)->days();
    $this->artisan('storyfeed:curate --window=1')->assertSuccessful();

    expect(Storyfeed::feed()->summary()->get()->toArray()['items'])->toHaveCount(3)
        ->and(Grouping::query()->where('winner', true)->pluck('bucket')->unique()->all())->toBe(['repeat'])
        ->and(curationPayloadMemberIds())->toBe($ids);

    $this->artisan('storyfeed:curate')->assertSuccessful();
    $node = Storyfeed::feed()->summary()->get()->toArray()['items'];

    expect($node)->toHaveCount(1)
        ->and($node[0]['axis'])->toBe('actors')
        ->and($node[0]['count'])->toBe(3)
        ->and(curationPayloadMemberIds())->toBe($ids);
});
