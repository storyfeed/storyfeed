<?php

namespace Storyfeed\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A candidate grouping hash for an activity along a named axis (`bucket`).
 * The substrate for feed curation — see docs/grouping.md.
 *
 * @property int $id
 * @property int $activity_id
 * @property string $hash
 * @property string|null $bucket
 * @property bool|null $winner Curation's stamp; null means never curated
 */
class Grouping extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    public function getTable(): string
    {
        return config('storyfeed.tables.groupings', 'feed_groupings');
    }

    /** @return BelongsTo<Model, $this> */
    public function activity(): BelongsTo
    {
        return $this->belongsTo($this->activityModel(), 'activity_id');
    }

    /** @return class-string<Model> */
    protected function activityModel(): string
    {
        return config('storyfeed.models.activity', Activity::class);
    }
}
