<?php

use Storyfeed\Events\Snapshots\ActivitySnapshot;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Payload\NodePresenter;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('adds only nullable role keys to baseline payload and event bytes without changing hashes', function () {
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
    foreach (['node', 'event'] as $kind) {
        $payload = json_decode($observed[$kind], true, flags: JSON_THROW_ON_ERROR);
        foreach (['origin', 'result', 'instrument'] as $role) {
            expect($payload)->toHaveKey($role, null);
            unset($payload[$role]);
        }
        $observed[$kind] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    expect($observed)->toBe(require __DIR__.'/../Fixtures/As2RolesBaseline.php');
});

it('exposes filled roles without changing existing grouping hashes', function () {
    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'W78']))->actor('Operator')->target('Destination')->publish();
    $presenter = app(NodePresenter::class);
    $before = json_encode($presenter->activityNode($activity->fresh()), JSON_THROW_ON_ERROR);
    $hashes = app(config('storyfeed.grouping.strategy'))->hashes($activity);
    $party = Storyfeed::party('Source tool or outcome');
    foreach (['origin', 'result', 'instrument'] as $role) {
        $activity->{$role}()->associate($party);
    }
    $activity->save();
    expect(json_encode($presenter->activityNode($activity->fresh()), JSON_THROW_ON_ERROR))->not->toBe($before)
        ->and(app(config('storyfeed.grouping.strategy'))->hashes($activity->fresh()))->toBe($hashes);
});

it('still reads event snapshots queued before the role extension', function () {
    $snapshot = unserialize(require __DIR__.'/../Fixtures/As2RolesLegacyEvent.php');
    $payload = $snapshot->toPayload();
    foreach (['origin', 'result', 'instrument'] as $role) {
        expect($payload)->toHaveKey($role, null);
        unset($payload[$role]);
    }
    expect($snapshot)->toBeInstanceOf(ActivitySnapshot::class)
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))
        ->toBe((require __DIR__.'/../Fixtures/As2RolesBaseline.php')['event']);
});
