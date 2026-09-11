<?php

namespace Storyfeed\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Storyfeed\Events\ActivityDeleted;
use Storyfeed\Events\Snapshots\ActivitySnapshot;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\Chronology;

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
 * @property string|null $origin_type
 * @property int|string|null $origin_id
 * @property int|null $cached_origin_id
 * @property string|null $result_type
 * @property int|string|null $result_id
 * @property int|null $cached_result_id
 * @property string|null $instrument_type
 * @property int|string|null $instrument_id
 * @property int|null $cached_instrument_id
 * @property array<array-key, mixed>|null $data
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property string|null $group_bucket read-time alias selected by FeedBuilder's grouping join
 * @property string|null $group_hash read-time alias selected by FeedBuilder's grouping join
 */
class Activity extends Model
{
    use HasUlids;
    use SoftDeletes;

    protected $guarded = [];

    /**
     * Microseconds on every date column, so `published_at` carries the
     * chronology the column was widened to hold. Applies to created_at,
     * updated_at and deleted_at too — a model has one format — which is why
     * the create stub and the upgrade migration widen all four together.
     * See Support\Chronology.
     */
    protected $dateFormat = Chronology::FORMAT;

    private bool $skipDefaultActor = false;

    /**
     * Whether the row was live when delete() was called. Read in `deleted`,
     * where a soft delete has already synced deleted_at into the originals
     * and a force delete never touches it, so neither can tell after the fact.
     */
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

    /** @return array<int, string> */
    public function uniqueIds(): array
    {
        return ['uid'];
    }

    /** @internal Carry builder intent through the creating hook; never persisted. */
    public function withoutDefaultActor(bool $skip = true): static
    {
        $this->skipDefaultActor = $skip;

        return $this;
    }

    protected static function booted(): void
    {
        static::creating(function (self $activity) {
            if (! $activity->skipDefaultActor && $activity->actor_type === null && $activity->actor_id === null) {
                app(StoryfeedManager::class)->applyDefaultActor($activity);
            }

            if ($activity->published_at === null) {
                $activity->published_at = now();
            }
        });

        static::deleted(function (self $activity) {
            ActivityDeleted::dispatch(ActivitySnapshot::fromModel($activity));
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

    /** @return MorphTo<Model, $this> */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function object(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphTo<Model, $this> */
    public function context(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Snapshot, $this> */
    public function cachedActor(): BelongsTo
    {
        return $this->belongsTo($this->snapshotModel(), 'cached_actor_id');
    }

    /** @return BelongsTo<Snapshot, $this> */
    public function cachedObject(): BelongsTo
    {
        return $this->belongsTo($this->snapshotModel(), 'cached_object_id');
    }

    /** @return BelongsTo<Snapshot, $this> */
    public function cachedTarget(): BelongsTo
    {
        return $this->belongsTo($this->snapshotModel(), 'cached_target_id');
    }

    /** @return BelongsTo<Snapshot, $this> */
    public function cachedContext(): BelongsTo
    {
        return $this->belongsTo($this->snapshotModel(), 'cached_context_id');
    }

    /** @return MorphTo<Model, $this> */
    public function origin(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Snapshot, $this> */
    public function cachedOrigin(): BelongsTo
    {
        return $this->belongsTo($this->snapshotModel(), 'cached_origin_id');
    }

    /** @return MorphTo<Model, $this> */
    public function result(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Snapshot, $this> */
    public function cachedResult(): BelongsTo
    {
        return $this->belongsTo($this->snapshotModel(), 'cached_result_id');
    }

    /** @return MorphTo<Model, $this> */
    public function instrument(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Snapshot, $this> */
    public function cachedInstrument(): BelongsTo
    {
        return $this->belongsTo($this->snapshotModel(), 'cached_instrument_id');
    }

    /** @return HasMany<Model, $this> */
    public function groupings(): HasMany
    {
        return $this->hasMany($this->groupingModel(), 'activity_id');
    }

    /** @return class-string<Snapshot> */
    protected function snapshotModel(): string
    {
        return config('storyfeed.models.snapshot', Snapshot::class);
    }

    /** @return class-string<Model> */
    protected function groupingModel(): string
    {
        return config('storyfeed.models.grouping', Grouping::class);
    }
}
