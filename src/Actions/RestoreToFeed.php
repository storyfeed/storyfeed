<?php

namespace Storyfeed\Actions;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Models\FeedTombstone;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\SyncToken;

/**
 * Undo TombstoneEntity for a model that came back. What a Feedable's
 * `restored` event does, whether the model uses InteractsWithFeed or was
 * registered with `Storyfeed::feedable()`, and what the trickle does for a
 * tombstone whose model exists again.
 *
 * It re-snapshots the model, repoints every reference to the tombstone back
 * to the model (see RepointReferences), and deletes the tombstone and its
 * snapshot. `sync_token` is bumped once when any activity moved.
 *
 * Activities the old cascade soft-deleted before tombstones existed stay
 * deleted: a restore only reverses what a tombstone recorded.
 */
class RestoreToFeed
{
    public function __invoke(Model $model): void
    {
        if (! TombstoneEntity::installed()) {
            (new SnapshotEntity)($model);

            return;
        }

        $tombstones = config('storyfeed.models.tombstone', FeedTombstone::class);
        $this->tombstone($tombstones::for($model->getMorphClass(), $model->getKey()), $model);
    }

    /**
     * Repoint one tombstone back to its model. With no tombstone, the model
     * is only re-snapshotted.
     */
    public function tombstone(?FeedTombstone $tombstone, Model $model): void
    {
        $snapshot = (new SnapshotEntity)($model);

        if ($tombstone === null) {
            return;
        }

        $moved = (new RepointReferences)(
            $tombstone->getMorphClass(),
            $tombstone->getKey(),
            $model->getMorphClass(),
            $model->getKey(),
            $snapshot->getKey(),
        );

        $snapshots = config('storyfeed.models.snapshot', Snapshot::class);
        $snapshots::query()->where('model_type', $tombstone->getMorphClass())->where('model_id', $tombstone->getKey())->delete();

        $tombstone->delete();

        if ($moved > 0) {
            SyncToken::bump();
        }
    }
}
