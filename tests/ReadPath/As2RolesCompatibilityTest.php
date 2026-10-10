<?php

use Storyfeed\Events\Snapshots\ActivitySnapshot;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Support\Avatar;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

it('adds only nullable role keys to baseline payload and event bytes without changing activity or event bytes', function () {
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
        foreach (['origin', 'result', 'instrument', 'location', 'generator'] as $role) {
            expect($payload)->toHaveKey($role, null);
            unset($payload[$role]);
        }
        // The time range (#78) is the same kind of addition: nullable keys.
        if ($kind === 'node') {
            // The featured role (#76): the object, by default.
            expect($payload)->toHaveKey('featured', 'object');
            unset($payload['featured']);

            foreach (['starts_at', 'ends_at'] as $key) {
                expect($payload)->toHaveKey($key, null);
                unset($payload[$key]);
            }

            // Every entity has an avatar (#92): the baseline's `media: null`
            // is now the derived one, initials on a palette colour.
            foreach (['actor', 'object', 'target', 'context'] as $role) {
                expect($payload[$role]['media'])->toMatchArray(['icon' => null, 'initials' => Avatar::initials($payload[$role]['label'])]);
                $payload[$role]['media'] = null;
            }
        }
        $observed[$kind] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    $baseline = require __DIR__.'/../Fixtures/As2RolesBaseline.php';
    // Live intentionally replaces calendar grouping hashes; byte compatibility is independent.
    unset($observed['hashes'], $baseline['hashes']);
    if ($activity->getConnection()->getDriverName() === 'mysql') {
        // The frozen bytes were captured on SQLite. MySQL's native JSON
        // columns reorder object keys; retain strict content and hash checks.
        foreach (['node', 'event'] as $kind) {
            $observed[$kind] = json_encode(jsonObjectKeys(json_decode($observed[$kind], true, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
            $baseline[$kind] = json_encode(jsonObjectKeys(json_decode($baseline[$kind], true, flags: JSON_THROW_ON_ERROR)), JSON_THROW_ON_ERROR);
        }
    }
    expect($observed)->toBe($baseline);
});

it('exposes filled roles without changing existing grouping hashes', function () {
    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'W78']))->actor('Operator')->target('Destination')->publish();
    $presenter = app(NodePresenter::class);
    $before = json_encode($presenter->activityNode($activity->fresh()), JSON_THROW_ON_ERROR);
    $hashes = app(config('storyfeed.grouping.strategy'))->hashes($activity);
    $party = Storyfeed::party('Source tool or outcome');
    foreach (['origin', 'result', 'instrument', 'location', 'generator'] as $role) {
        $activity->{$role}()->associate($party);
    }
    $activity->save();
    expect(json_encode($presenter->activityNode($activity->fresh()), JSON_THROW_ON_ERROR))->not->toBe($before)
        ->and(app(config('storyfeed.grouping.strategy'))->hashes($activity->fresh()))->toBe($hashes);
});

it('still reads event snapshots queued before the role extension', function () {
    $snapshot = unserialize(require __DIR__.'/../Fixtures/As2RolesLegacyEvent.php');
    $payload = $snapshot->toPayload();
    foreach (['origin', 'result', 'instrument', 'location', 'generator'] as $role) {
        expect($payload)->toHaveKey($role, null);
        unset($payload[$role]);
    }
    // Queued before `component` retired: the key is still in the bytes, and
    // is all that differs.
    foreach (['actor', 'object', 'target', 'context'] as $role) {
        expect($payload[$role])->toHaveKey('component');
        unset($payload[$role]['component']);
    }
    expect($snapshot)->toBeInstanceOf(ActivitySnapshot::class)
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))
        ->toBe((require __DIR__.'/../Fixtures/As2RolesBaseline.php')['event']);
});
