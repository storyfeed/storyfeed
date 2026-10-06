<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Actions\PurgeActivities;
use Storyfeed\Actions\RepointReferences;
use Storyfeed\Actions\RestoreToFeed;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\Actions\TombstoneEntity;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Batch;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\ActivityRoles;
use Workbench\App\Models\Customer;

it('binds numeric model keys as strings in role and batch lookups', function () {
    $model = Customer::create(['name' => 'Binding probe']);
    DB::enableQueryLog();
    foreach (['actor', 'object', 'target', 'context'] as $scope) {
        Activity::query()->{$scope}($model)->get();
    }
    Batch::query()->forActor($model)->get();
    foreach (DB::getQueryLog() as $query) {
        expect($query['bindings'][1])->toBe((string) $model->getKey());
    }
});

it('binds snapshot identity as a string for both lookup and insert', function () {
    $model = Customer::create(['id' => 987650, 'name' => 'Snapshot probe']);
    DB::enableQueryLog();
    (new SnapshotEntity)($model);
    (new SnapshotEntity)($model);
    $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'feed_snapshots'));
    expect($queries)->not->toBeEmpty();
    foreach ($queries as $query) {
        expect($query['bindings'])->not->toContain($model->getKey());
    }
});

it('binds every role insert and repoint value as a string while preserving package keys', function () {
    $values = ['verb' => 'probe'];
    foreach (ActivityRoles::STORED as $role) {
        $values[$role.'_type'] = 'probe';
        $values[$role.'_id'] = 987654;
    }
    DB::enableQueryLog();
    $activity = Activity::create($values);
    (new RepointReferences)('probe', 987654, 'replacement', 987655, null);
    $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'feed_activities') || str_contains($query['query'], 'feed_participants'));
    foreach ($queries as $query) {
        expect($query['bindings'])->not->toContain(987654, 987655);
    }
    expect($activity->id)->toBeInt();
    foreach (ActivityRoles::STORED as $role) {
        expect($activity->fresh()->{$role.'_id'})->toBe('987655');
    }
});

it('normalizes numeric array keys before snapshot sweep bindings', function () {
    $model = Customer::create(['id' => 987651, 'name' => 'Sweep probe']);
    (new SnapshotEntity)($model);
    DB::enableQueryLog();
    (new PurgeActivities)->sweep(['customer' => ['987651' => true]]);
    $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'feed_snapshots'));
    expect($queries)->not->toBeEmpty();
    foreach ($queries as $query) {
        expect($query['bindings'])->toContain('987651')->not->toContain(987651);
    }
});

it('normalizes direct batch and snapshot writes and leaves absent roles null', function () {
    DB::enableQueryLog();
    $batch = Batch::create(['actor_type' => 'probe', 'actor_id' => 987652, 'opened_at' => now()]);
    $snapshot = Snapshot::create(['model_type' => 'probe', 'model_id' => 987652]);
    $tombstone = FeedTombstone::create(['model_type' => 'probe', 'model_id' => 987652, 'deleted_at' => now()]);
    $activity = Activity::create(['verb' => 'probe']);
    $batch->update(['actor_id' => 987653]);
    $snapshot->update(['model_id' => 987653]);
    $tombstone->update(['model_id' => 987653]);
    foreach (DB::getQueryLog() as $query) {
        expect($query['bindings'])->not->toContain(987652, 987653);
    }
    expect($activity->actor_id)->toBeNull()
        ->and($activity->object_id)->toBeNull()
        ->and($batch->actor_id)->toBe('987653');
});

it('binds numeric tombstone keys as strings when naming roles and deleting their snapshots', function () {
    $model = Customer::create(['id' => 987654, 'name' => 'Restore probe']);
    $tombstone = FeedTombstone::create(['id' => 987655, 'model_type' => 'customer', 'model_id' => $model->id, 'deleted_at' => now()]);
    Activity::create(['verb' => 'probe', 'object_type' => $tombstone->getMorphClass(), 'object_id' => $tombstone->id]);
    DB::enableQueryLog();
    $action = new class extends TombstoneEntity
    {
        public function references(FeedTombstone $tombstone): bool
        {
            return $this->named($tombstone);
        }
    };
    expect($action->references($tombstone))->toBeTrue();
    (new RestoreToFeed)->tombstone($tombstone, $model);
    $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'feed_activities') || str_contains($query['query'], 'feed_snapshots'));
    foreach ($queries as $query) {
        expect($query['bindings'])->not->toContain(987654, 987655);
    }
    expect(Activity::query()->sole()->object_id)->toBe('987654');
});
