<?php

namespace Storyfeed\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\StoryfeedManager;

/**
 * A recorded activity: actor + verb + object, optionally aimed at a target
 * within a context.
 *
 * The bigint `id` is internal; `uid` (ULID) is the public identity used in
 * payloads and IRIs.
 *
 * Role columns store morph ALIASES — compare with $model->getMorphClass(),
 * never get_class(). The query scopes on ActivityBuilder handle this.
 *
 * @property int $id
 * @property string $uid
 * @property string $verb
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property string|null $object_type
 * @property int|string|null $object_id
 * @property string|null $target_type
 * @property int|string|null $target_id
 * @property string|null $context_type
 * @property int|string|null $context_id
 * @property int|null $cached_actor_id
 * @property int|null $cached_object_id
 * @property int|null $cached_target_id
 * @property int|null $cached_context_id
 * @property array<array-key, mixed>|null $data
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Activity extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return config('storyfeed.tables.activities', 'feed_activities');
    }

    public function uniqueIds(): array
    {
        return ['uid'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $activity) {
            if ($activity->actor_type === null && $activity->actor_id === null) {
                if ($actor = app(StoryfeedManager::class)->resolveActor()) {
                    $activity->actor()->associate($actor);
                }
            }

            if ($activity->published_at === null) {
                $activity->published_at = now();
            }
        });
    }

    /**
     * @return ActivityBuilder<static>
     */
    public function newEloquentBuilder($query): ActivityBuilder
    {
        /** @var ActivityBuilder<static> */
        return new ActivityBuilder($query);
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function object(): MorphTo
    {
        return $this->morphTo();
    }

    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    public function cachedActor(): BelongsTo
    {
        return $this->belongsTo($this->snapshotModel(), 'cached_actor_id');
    }

    public function cachedObject(): BelongsTo
    {
        return $this->belongsTo($this->snapshotModel(), 'cached_object_id');
    }

    public function cachedTarget(): BelongsTo
    {
        return $this->belongsTo($this->snapshotModel(), 'cached_target_id');
    }

    public function cachedContext(): BelongsTo
    {
        return $this->belongsTo($this->snapshotModel(), 'cached_context_id');
    }

    public function groupings(): HasMany
    {
        return $this->hasMany(
            config('storyfeed.models.grouping', Grouping::class),
            'activity_id',
        );
    }

    protected function snapshotModel(): string
    {
        return config('storyfeed.models.snapshot', Snapshot::class);
    }
}
