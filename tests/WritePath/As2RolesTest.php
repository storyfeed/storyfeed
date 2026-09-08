<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\BundleComposites;
use Storyfeed\Actions\RebuildSnapshots;
use Storyfeed\Actions\TrickleSnapshots;
use Storyfeed\Events\Snapshots\ActivitySnapshot;
use Storyfeed\Events\Snapshots\BatchSnapshot;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Batch;
use Storyfeed\Models\Party;
use Storyfeed\Payload\NodePresenter;
use Storyfeed\Serialization\Reader;
use Storyfeed\Support\ActivityRoles;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Customer;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;
use Workbench\App\Stories\DeliveryWasConfirmed;

it('stores snapshots and scopes participation for each AS2 role', function (string $role) {
    $entity = Customer::create(['name' => 'Source or tool or outcome']);
    $activity = Storyfeed::activity('confirm')->{$role}($entity)->publish()->fresh();

    expect($activity->{$role.'_type'})->toBe($entity->getMorphClass())
        ->and((string) $activity->{$role.'_id'})->toBe((string) $entity->id)
        ->and($activity->{$role}->is($entity))->toBeTrue()
        ->and($activity->{'cached'.ucfirst($role)}->label)->toBe($entity->name)
        ->and(Activity::query()->involving($entity)->pluck('id')->all())->toBe([$activity->id])
        ->and(DB::table('feed_participants')->where('activity_id', $activity->id)->value('role'))->toBe($role);

    $document = serialize_one($activity);
    expect($document[$role]['name'])->toBe($entity->name)
        ->and(app(Reader::class)->activity(json_decode(json_encode($document), true))[$role])->toBe($document[$role]);
})->with(['origin', 'result', 'instrument']);

it('mints a Party and keeps explicit null a no-op in each helper', function (string $method, string $role) {
    $activity = Storyfeed::activity('confirm')->{$method}('Tablet')->{$method}(null)->publish()->fresh();
    expect($activity->{$role.'_type'})->toBe((new Party)->getMorphClass())
        ->and($activity->{$role})->toBeInstanceOf(Party::class)
        ->and(serialize_one($activity)[$role]['name'])->toBe('Tablet');

    $empty = Storyfeed::activity('confirm')->{$method}(null)->publish();
    expect($empty->{$role.'_type'})->toBeNull()
        ->and(serialize_one($empty))->not->toHaveKey($role)
        ->and(app(Reader::class)->activity(serialize_one($empty))[$role])->toBeNull();
})->with([
    ['origin', 'origin'], ['result', 'result'], ['instrument', 'instrument'],
    ['using', 'instrument'], ['resulting', 'result'],
]);

it('preserves from as target and forwards enum helpers and all record entry points', function () {
    $delivery = Delivery::create(['tracking_number' => 'W78']);
    $activity = ActivityVerb::Confirm->origin('Warehouse')->using('Tablet')->resulting('Receipt')->from('Destination')->publish();
    expect($activity->target->name)->toBe('Destination')
        ->and($activity->origin->name)->toBe('Warehouse')
        ->and($activity->instrument->name)->toBe('Tablet')
        ->and($activity->result->name)->toBe('Receipt');

    $records = [
        Storyfeed::record('confirm', object: $delivery, origin: 'Warehouse', result: 'Receipt', instrument: 'Tablet'),
        ActivityVerb::Confirm->record(object: $delivery, origin: 'Warehouse', result: 'Receipt', instrument: 'Tablet'),
        DeliveryWasConfirmed::record(object: $delivery, origin: 'Warehouse', result: 'Receipt', instrument: 'Tablet'),
    ];
    foreach ($records as $record) {
        expect($record->origin->name)->toBe('Warehouse')
            ->and($record->result->name)->toBe('Receipt')
            ->and($record->instrument->name)->toBe('Tablet');
    }
});

it('repairs snapshots and reports unresolved new roles without pruning', function (string $role) {
    $entity = Customer::create(['name' => 'Recoverable']);
    $activity = Activity::create(['verb' => 'confirm', $role.'_type' => 'customer', $role.'_id' => $entity->id]);
    expect(Activity::query()->uncached()->pluck('id')->all())->toBe([$activity->id]);
    expect((new RebuildSnapshots)()['snapshotted'])->toBe(1)
        ->and($activity->fresh()->{'cached'.ucfirst($role)}->label)->toBe('Recoverable');

    $activity->update(['cached_'.$role.'_id' => null]);
    expect((new TrickleSnapshots)()['snapshotted'])->toBe(1);
    $activity->refresh()->update(['cached_'.$role.'_id' => null, $role.'_id' => 999999]);
    expect((new TrickleSnapshots)(prune: false)['unresolved'])->toBe(1)
        ->and($activity->fresh()->deleted_at)->toBeNull();
    expect(serialize_one($activity)[$role])->toHaveKey('type');
})->with(['origin', 'result', 'instrument']);

it('freezes new facts in activity and batch events across serialization and later deletion', function () {
    $actor = User::create(['name' => 'Operator', 'email' => 'operator@example.com']);
    $activity = Storyfeed::activity('confirm')->actor($actor)->origin('Source')->using('Tool')->resulting('Output')->publish();
    $snapshot = ActivitySnapshot::fromModel($activity);
    $batch = BatchSnapshot::fromModel(Batch::firstOrFail());
    $payload = $snapshot->toPayload();
    foreach (['origin' => 'Source', 'instrument' => 'Tool', 'result' => 'Output'] as $role => $label) {
        expect($payload[$role]['label'])->toBe($label)
            ->and($batch->activities[0]->toPayload()[$role])->toBe($payload[$role]);
    }
    $activity->origin->delete();
    $activity->forceDelete();
    expect(unserialize(serialize($snapshot))->toPayload())->toBe($payload)
        ->and(unserialize(serialize($batch))->activities[0]->toPayload())->toBe($payload);

    $old = ActivitySnapshot::fromModel(Storyfeed::activity('confirm')->publish())->toPayload();
    expect($old)->toHaveKeys(['origin', 'result', 'instrument']);
    foreach (['origin', 'result', 'instrument'] as $role) {
        expect($old[$role])->toBeNull();
    }
});

it('keeps automatic composite parents free of inferred provenance and preserves explicit composite facts', function () {
    Storyfeed::collectables(['delivery']);
    $actor = User::create(['name' => 'Operator', 'email' => 'operator@example.com']);
    foreach (['Source A', 'Source B'] as $i => $source) {
        Storyfeed::activity('upload', Delivery::create(['tracking_number' => (string) $i]))
            ->actor($actor)->origin($source)->using('Tool')->resulting('Output '.$i)->publish();
    }
    expect((new BundleComposites)(Batch::firstOrFail()))->toBe(1);
    $parent = Activity::whereNull('object_type')->firstOrFail();
    expect(serialize_one($parent))->not->toHaveKeys(['origin', 'result', 'instrument']);
    $node = app(NodePresenter::class)->activityNode($parent);
    foreach (['origin', 'result', 'instrument'] as $role) {
        expect($node)->toHaveKey($role, null);
    }
    expect(Activity::whereNotNull('object_type')->get()->map(fn ($a) => serialize_one($a)['origin']['name'])->all())
        ->toBe(['Source A', 'Source B']);

    $explicit = Storyfeed::activity('upload')->objects([
        Delivery::create(['tracking_number' => 'X']), Delivery::create(['tracking_number' => 'Y']),
    ])->origin('Declared source')->using('Declared tool')->resulting('Declared output')->publish();
    foreach (Activity::where('origin_id', $explicit->origin_id)->get() as $member) {
        expect(serialize_one($member)['origin']['name'])->toBe('Declared source')
            ->and(serialize_one($member)['instrument']['name'])->toBe('Declared tool')
            ->and(serialize_one($member)['result']['name'])->toBe('Declared output');
    }
    expect(Activity::where('origin_id', $explicit->origin_id)->count())->toBe(3);
});

it('keeps separate stored payload and groupable selections after promotion', function () {
    expect(ActivityRoles::STORED)->toBe(['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'])
        ->and(ActivityRoles::PAYLOAD)->toBe(['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument'])
        ->and(ActivityRoles::GROUPABLE)->toBe(['actor', 'object', 'target', 'context', 'origin', 'result', 'instrument']);
});
