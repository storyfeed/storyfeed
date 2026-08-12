<?php

namespace Storyfeed\Actions;

use Illuminate\Database\Eloquent\Model;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\ShapeSignature;

/**
 * Upsert the snapshot row for a single Feedable model. Every write stamps
 * the entity's SHAPE signature, so a later change to toFeed()'s structure
 * (wherever it originates — including DTOs feeding `data`) makes older
 * rows detectably stale; the trickle converges them (docs/grouping.md).
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
                'shape' => ShapeSignature::for($entity, $model::class),
            ],
        );
    }
}
