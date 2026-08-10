<?php

namespace Storyfeed\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A candidate grouping hash for an activity along a named axis (`bucket`).
 * The substrate for feed curation — see docs/grouping.md.
 */
class Grouping extends Model
{
    protected $guarded = [];

    public function getTable(): string
    {
        return config('storyfeed.tables.groupings', 'feed_groupings');
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(
            config('storyfeed.models.activity', Activity::class),
            'activity_id',
        );
    }
}
