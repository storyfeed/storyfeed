<?php

namespace Storyfeed\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The denormalized read-model snapshot of a Feedable entity: its label,
 * renderer component hint, and cacheable data. One row per entity, refreshed
 * on save; links are regenerated live from `data` at read time.
 *
 * `meta` holds the package's snapshot extras that are never queried or
 * indexed — today, the route key. Anything a query filters, sorts or joins on
 * stays a real column, like `shape`. `meta` is the package's and `data` is
 * the app's: the two are never merged, and FeedContext::data() never
 * returns `meta`.
 *
 * @property int $id
 * @property string $model_type
 * @property int|string $model_id
 * @property string|null $label
 * @property string|null $content
 * @property string|null $media_type
 * @property string|null $attributed_to
 * @property string|null $component
 * @property array<array-key, mixed>|null $data
 * @property list<array<string, mixed>>|null $body
 * @property string|null $source_updated_at UTC source time with microseconds; distinct from snapshot write time
 * @property string|null $shape shape fingerprint at write time (see ShapeSignature)
 * @property array{route_key?: string|null}|null $meta the package's extras, never queried or indexed; null on rows written before it existed
 */
class Snapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'body' => 'array',
            'meta' => 'array',
        ];
    }

    public function getTable(): string
    {
        return config('storyfeed.tables.snapshots', 'feed_snapshots');
    }

    /** @return MorphTo<Model, $this> */
    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
