<?php

namespace Storyfeed\Actions;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Models\Snapshot;
use Storyfeed\Support\Ancestors;
use Storyfeed\Support\Feedables;
use Storyfeed\Support\MorphKeyType;
use Storyfeed\Support\ShapeSignature;

/**
 * Upsert the snapshot row for a single Feedable model. Every write stamps
 * the entity's SHAPE signature, so a later change to toFeed()'s structure
 * (wherever it originates — including DTOs feeding `data`) makes older
 * rows detectably stale; the trickle converges them (docs/grouping.md).
 *
 * @internal
 */
class SnapshotEntity
{
    /** @var array<string, true> by connection and table */
    private static array $installed = [];

    /**
     * Whether the snapshots table exists. A Feedable model's save checks
     * first, so an app without core's tables (or a deploy that runs its code
     * before `migrate`) saves the model and writes nothing, rather than
     * failing a save that has nothing to do with the feed. Only a positive
     * answer is remembered, per connection, as TombstoneEntity::installed().
     */
    public static function installed(): bool
    {
        $model = new (config('storyfeed.models.snapshot', Snapshot::class));
        $key = $model->getConnection()->getName().'|'.$model->getTable();

        if (isset(self::$installed[$key])) {
            return true;
        }

        if (! Schema::connection($model->getConnectionName())->hasTable($model->getTable())) {
            return false;
        }

        return self::$installed[$key] = true;
    }

    public function __invoke(Model $model): Snapshot
    {
        $snapshot = config('storyfeed.models.snapshot', Snapshot::class);

        [$identity, $values, $sourceUpdatedAt] = $this->describe($model);

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

    /**
     * The snapshot this model would write, unsaved: what a source that never
     * touches the database presents a model role from.
     */
    public function make(Model $model): Snapshot
    {
        $snapshot = config('storyfeed.models.snapshot', Snapshot::class);

        [$identity, $values] = $this->describe($model);

        return (new $snapshot)->forceFill([...$identity, ...$values]);
    }

    /** @return array{array<string, mixed>, array<string, mixed>, CarbonImmutable|null} */
    private function describe(Model $model): array
    {
        $entity = app(Feedables::class)->toFeed($model);

        $sourceUpdatedAt = $this->sourceUpdatedAt($model);
        $identity = [
            'model_type' => $model->getMorphClass(),
            'model_id' => MorphKeyType::value($model->getKey()),
        ];
        $routeKey = $model->getRouteKey();
        $values = [
            'label' => $entity->label,
            'data' => $entity->data,
            'body' => $entity->body === [] ? null : $entity->body,
            'content' => $entity->content,
            'media_type' => $entity->mediaType,
            'attributed_to' => $entity->attributedTo,
            'shape' => ShapeSignature::for($entity, $model::class),
            'source_updated_at' => $sourceUpdatedAt?->format('Y-m-d H:i:s.u'),
            // The route key only when it is not the primary key, which the
            // snapshot already carries. Always an array, so a written row is
            // told apart from one that predates `meta`; the trickle refreshes those.
            'meta' => array_merge(
                $routeKey === null || (string) $routeKey === (string) $model->getKey()
                    ? [] : ['route_key' => (string) $routeKey],
                ! $entity->parentDeclared ? [] : ['parent' => $entity->parent === null ? null : Ancestors::identity($entity->parent)],
            ),
        ];

        return [$identity, $values, $sourceUpdatedAt];
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
