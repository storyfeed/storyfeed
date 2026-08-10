<?php

namespace Storyfeed\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The denormalized read-model snapshot of a Feedable entity: its label,
 * renderer component hint, and cacheable data. One row per entity, refreshed
 * on save; links are regenerated live from `data` at read time.
 */
class Snapshot extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
        ];
    }

    public function getTable(): string
    {
        return config('storyfeed.tables.snapshots', 'feed_snapshots');
    }

    public function model(): MorphTo
    {
        return $this->morphTo();
    }
}
