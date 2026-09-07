<?php

namespace Storyfeed\Actions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
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

        $sourceUpdatedAt = $this->sourceUpdatedAt($model);
        $identity = [
            'model_type' => $model->getMorphClass(),
            'model_id' => $model->getKey(),
        ];
        $values = [
            'label' => $entity->label,
            'component' => $entity->component,
            'data' => $entity->data,
            'content' => $entity->content,
            'media_type' => $entity->mediaType,
            'attributed_to' => $entity->attributedTo,
            'shape' => ShapeSignature::for($entity, $model::class),
            'source_updated_at' => $sourceUpdatedAt?->format('Y-m-d H:i:s.u'),
        ];

        // Compare and save under the same row lock. firstOrCreate also handles
        // concurrent initial inserts through the existing unique entity key.
        return (new $snapshot)->getConnection()->transaction(function () use ($snapshot, $identity, $values, $sourceUpdatedAt): Snapshot {
            $row = $snapshot::query()->firstOrCreate($identity, $values);

            if ($row->wasRecentlyCreated) {
                return $row;
            }

            $row = $snapshot::query()->whereKey($row->getKey())->lockForUpdate()->firstOrFail();

            if ($sourceUpdatedAt !== null && $row->source_updated_at !== null
                && $sourceUpdatedAt->lessThan(CarbonImmutable::parse($row->source_updated_at, 'UTC'))) {
                return $row;
            }

            // Unknown source time cannot establish ordering. Write as before,
            // clearing the watermark so it still describes the stored payload.
            $row->fill($values)->save();

            return $row;
        });
    }

    private function sourceUpdatedAt(Model $model): ?CarbonImmutable
    {
        $column = $model->getUpdatedAtColumn();

        if (! $model->usesTimestamps() || $column === null || ! array_key_exists($column, $model->getAttributes())) {
            return null;
        }

        try {
            $value = $model->getAttribute($column);

            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value)->utc();
            }

            return is_string($value) && trim($value) !== ''
                ? CarbonImmutable::parse($value)->utc()
                : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
