<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Storyfeed\Actions\RebuildSnapshots;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Snapshot;
use Workbench\App\Models\Courier;
use Workbench\App\Models\User;

/**
 * A role id is a morph id, and a morph id is not always an integer. Consumers
 * who publish the migration and swap nullableMorphs for its ULID/UUID form
 * store string role ids, and `storyfeed:rebuild` has to read them back as the
 * strings they are.
 *
 * The trap this pins: the query aliased `{role}_id as id`, which lands on
 * Activity's PRIMARY KEY. Eloquent casts the primary key to the model's
 * keyType, so a ULID actor arrived at resolve() as the integer 1 — asking for
 * the wrong model, and then stamping cached_actor_id onto every row whose
 * actor_id was 1.
 */
it('hands a string role id to the resolver unchanged, not cast to its key type', function () {
    $ulid = (string) Str::ulid();

    DB::table('feed_activities')->insert([
        'uid' => (string) Str::ulid(),
        'verb' => 'order.note',
        'actor_type' => 'user',
        'actor_id' => $ulid,
        'published_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $action = new class extends RebuildSnapshots
    {
        /** @var list<int|string> */
        public array $seen = [];

        protected function resolve(string $type, int|string $id): ?Model
        {
            $this->seen[] = $id;

            return parent::resolve($type, $id);
        }
    };

    $action();

    expect($action->seen)->toContain($ulid);
});

/**
 * The same seam, end to end and against a real model rather than a stubbed
 * resolver: a ULID-keyed Feedable in a feed role has to come out of the
 * rebuild snapshotted, and its snapshot has to land on ITS activity.
 *
 * The second half is the one that mattered — the pre-fix rebuild did not
 * merely fail to resolve, it then ran `where actor_id = 1` and stamped
 * cached_actor_id onto whichever row answered.
 */
it('snapshots a ULID-keyed role model and stamps that snapshot on its own activity', function () {
    $courier = Courier::create(['name' => 'Ada']);
    $user = User::create(['name' => 'Grace', 'email' => 'grace@example.test']);

    $byCourier = Storyfeed::activity('delivery.handoff')->by($courier)->publish();
    $byUser = Storyfeed::activity('delivery.handoff')->by($user)->publish();

    // The write path already stamps these. Clear them so what is asserted
    // below is the rebuild's own work and not the publish's.
    DB::table('feed_activities')->update(['cached_actor_id' => null]);

    expect((new RebuildSnapshots)())->toMatchArray(['snapshotted' => 2, 'missing' => 0]);

    $snapshot = Snapshot::query()
        ->where('model_type', $courier->getMorphClass())
        ->where('model_id', $courier->getKey())
        ->sole();

    expect($byCourier->fresh()->cached_actor_id)->toBe($snapshot->getKey())
        ->and($byUser->fresh()->cached_actor_id)->not->toBe($snapshot->getKey());
});
