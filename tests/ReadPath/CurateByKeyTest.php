<?php

use Storyfeed\Actions\CurateCluster;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Grouping;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * CurateCluster reads an activity's grouping rows, then writes their winner
 * stamps by primary key (todo 1349: by `activity_id`, a small table on
 * MariaDB was scanned and every row locked). The read and the write are two
 * statements, so a row can disappear between them; that must write nothing
 * rather than fail.
 */

it('writes nothing for a grouping row that vanished between reading and stamping it', function () {
    $activity = Storyfeed::activity('upload')
        ->actor(User::create(['name' => 'Sally', 'email' => 'sally@example.test']))
        ->object(Delivery::create(['tracking_number' => 'k-1']))
        ->target(Customer::create(['name' => 'Acme']))
        ->publish();

    $curate = new class extends CurateCluster
    {
        public function settleWithStamps(int|string $activityId): void
        {
            $hashes = $this->hashes($activityId);
            $stamps = array_map(fn (array $stamp) => [$stamp[0], null], $this->winnerState($activityId));

            // Another transaction removes the row this settle would stamp.
            Grouping::query()->whereKey($stamps['repeat'][0])->delete();

            $this->settle($activityId, $hashes, $stamps);
        }
    };

    $curate->settleWithStamps($activity->id);

    $rows = Grouping::query()->where('activity_id', $activity->id)
        ->whereNotIn('bucket', ['composite', 'batch'])
        ->pluck('winner', 'bucket')->map(fn ($w) => (bool) $w)->all();

    expect($rows)->not->toHaveKey('repeat')
        ->and(array_filter($rows))->toBe([]);
});

it('stamps only the settled activity\'s own rows', function () {
    $sally = User::create(['name' => 'Sally', 'email' => 'sally@example.test']);
    $acme = Customer::create(['name' => 'Acme']);
    $publish = fn (string $n) => Storyfeed::activity('upload')->actor($sally)
        ->object(Delivery::create(['tracking_number' => $n]))->target($acme)->publish();

    $first = $publish('k-1');
    $second = $publish('k-2');
    $others = fn () => Grouping::query()->where('activity_id', $first->id)->orderBy('id')->get(['id', 'winner'])->toArray();
    $before = $others();

    Grouping::query()->where('activity_id', $second->id)->where('bucket', '!=', 'batch')->update(['winner' => null]);
    (new CurateCluster)($second);

    expect($others())->toBe($before)
        ->and(Grouping::query()->where('activity_id', $second->id)->where('winner', true)->count())->toBe(1);
});
