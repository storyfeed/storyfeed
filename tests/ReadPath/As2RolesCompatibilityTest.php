<?php

use Storyfeed\Events\Snapshots\ActivitySnapshot;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Support\ActivityRoles;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('preserves baseline payload bytes event bytes and grouping hashes without new roles', function () {
    $this->travelTo('2026-09-08 12:00:00');
    $user = User::create(['name' => 'Operator', 'email' => 'operator@example.com']);
    $delivery = Delivery::create(['tracking_number' => 'W78']);
    $target = Customer::create(['name' => 'Destination']);
    $context = Customer::create(['name' => 'Workspace']);
    $activity = Storyfeed::activity('confirm', $delivery)->actor($user)->target($target)->context($context)->publish();
    $activity->update(['uid' => '01J00000000000000000000000']);
    $activity = $activity->fresh();
    $observed = [
        'node' => json_encode(app(NodePresenter::class)->activityNode($activity), JSON_THROW_ON_ERROR),
        'event' => json_encode(ActivitySnapshot::fromModel($activity)->toPayload(), JSON_THROW_ON_ERROR),
        'hashes' => app(config('storyfeed.grouping.strategy'))->hashes($activity),
    ];
    expect($observed)->toBe(require __DIR__.'/../Fixtures/As2RolesBaseline.php');
});

it('keeps filled storage roles out of payload nodes and existing grouping hashes', function () {
    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'W78']))->actor('Operator')->target('Destination')->publish();
    $presenter = app(NodePresenter::class);
    $before = json_encode($presenter->activityNode($activity->fresh()), JSON_THROW_ON_ERROR);
    $hashes = app(config('storyfeed.grouping.strategy'))->hashes($activity);
    $party = Storyfeed::party('Source tool or outcome');
    foreach (array_diff(ActivityRoles::STORED, ActivityRoles::PAYLOAD) as $role) {
        $activity->{$role}()->associate($party);
    }
    $activity->save();
    expect(json_encode($presenter->activityNode($activity->fresh()), JSON_THROW_ON_ERROR))->toBe($before)
        ->and(app(config('storyfeed.grouping.strategy'))->hashes($activity->fresh()))->toBe($hashes);
});

it('still reads event snapshots queued before the role extension', function () {
    $snapshot = unserialize(require __DIR__.'/../Fixtures/As2RolesLegacyEvent.php');
    expect($snapshot)->toBeInstanceOf(ActivitySnapshot::class)
        ->and(json_encode($snapshot->toPayload(), JSON_THROW_ON_ERROR))
        ->toBe((require __DIR__.'/../Fixtures/As2RolesBaseline.php')['event']);
});
