<?php

namespace Storyfeed\Actions;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Models\Snapshot;

/**
 * Upsert the snapshot row for a single Feedable model.
 */
class SnapshotEntity
{
    public function __invoke(Model&Feedable $model): Snapshot
    {
        $entity = $model->toFeed();

        $snapshot = config('storyfeed.models.snapshot', Snapshot::class);

        return $snapshot::query()->updateOrCreate(
            [
                'model_type' => $model->getMorphClass(),
                'model_id' => $model->getKey(),
            ],
            [
                'label' => $entity->label,
                'component' => $entity->component,
                'data' => $entity->data,
            ],
        );
    }
}
