<?php

namespace Storyfeed\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Storyfeed\Actions\SyncParticipants;
use Storyfeed\Events\ActivityDeleted;
use Storyfeed\Events\Snapshots\ActivitySnapshot;
use Storyfeed\Models\Builders\ActivityBuilder;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\ActivityRoles;
use Storyfeed\Support\Chronology;
use Storyfeed\Support\InlineEntity;
use Storyfeed\Support\MorphKeyType;

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
 * @property string|null $verb null only on an entry composed with no verb, read in memory; a stored activity always has one
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
 * @property string|null $location_type
 * @property int|string|null $location_id
 * @property int|null $cached_location_id
 * @property string|null $generator_type
 * @property int|string|null $generator_id
 * @property int|null $cached_generator_id
 * @property string|null $featured the role whose entity the row draws; 'object' by default, null for none
 * @property array<array-key, mixed>|null $data
 * @property array<string, array<string, mixed>>|null $entities roles with no model behind them, by role (see Support\InlineEntity)
 * @property Carbon|null $published_at
 * @property Carbon|null $starts_at
 * @property Carbon|null $ends_at
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
            'actor_id' => MorphKeyType::class,
            'object_id' => MorphKeyType::class,
            'target_id' => MorphKeyType::class,
            'context_id' => MorphKeyType::class,
            'origin_id' => MorphKeyType::class,
            'result_id' => MorphKeyType::class,
            'instrument_id' => MorphKeyType::class,
            'location_id' => MorphKeyType::class,
            'generator_id' => MorphKeyType::class,
            'data' => 'array',
            'entities' => 'array',
            'published_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
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

        // involving() orders a feed by the participant rows' copy of
        // published_at, so a rescheduled activity moves its rows with it.
        static::updated(function (self $activity) {
            if ($activity->wasChanged('published_at')) {
                SyncParticipants::retime($activity);
            }
        });

        static::deleted(function (self $activity) {
            ActivityDeleted::dispatch(ActivitySnapshot::fromModel($activity));
        });
    }

    /**
     * The role's entity when it has no model behind it, as stored: `type`,
     * `id`, `label`, and `url`, `data` and `body` when given. Null when the
     * role is a model, a party, or empty.
     *
     * @return array<string, mixed>|null
     */
    public function inlineEntity(string $role): ?array
    {
        $entity = $this->entities[$role] ?? null;

        return is_array($entity) ? $entity : null;
    }

    /**
     * A role with no model behind it reads as its stored snapshot, and its
     * live model is null: nothing hydrates it, and its type names no class.
     */
    public function getRelationValue($key)
    {
        if ($this->entities !== null) {
            if (str_starts_with($key, 'cached') && ($entity = $this->inlineEntity(lcfirst(substr($key, 6)))) !== null) {
                if (! $this->relationLoaded($key) || $this->getRelation($key) === null) {
                    $this->setRelation($key, InlineEntity::snapshot($entity));
                }

                return $this->getRelation($key);
            }

            if (in_array($key, ActivityRoles::STORED, true) && $this->inlineEntity($key) !== null) {
                return null;
            }
        }

        return parent::getRelationValue($key);
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

    /** @return MorphTo<Model, $this> */
    public function location(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Snapshot, $this> */
    public function cachedLocation(): BelongsTo
    {
        return $this->belongsTo($this->snapshotModel(), 'cached_location_id');
    }

    /** @return MorphTo<Model, $this> */
    public function generator(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<Snapshot, $this> */
    public function cachedGenerator(): BelongsTo
    {
        return $this->belongsTo($this->snapshotModel(), 'cached_generator_id');
    }

    /**
     * The name of the story this activity was published under, as
     * `Route::currentRouteName()` gives a request's: looked up from its
     * `object_type` and `verb` when read, never stored, so renaming a story
     * costs nothing. Null when nothing names its key.
     */
    public function storyName(): ?string
    {
        return app(StoryfeedManager::class)->storyNameFor($this->object_type, $this->verb);
    }

    /**
     * Whether the story's name matches a pattern, `request()->routeIs()`'s
     * twin (Illuminate/Routing/Route.php, named()): `storyIs('order.*')`.
     * False for an activity whose key has no name.
     */
    public function storyIs(string ...$patterns): bool
    {
        if (($name = $this->storyName()) === null) {
            return false;
        }

        return array_any($patterns, fn (string $pattern) => Str::is($pattern, $name));
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
