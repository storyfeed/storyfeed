<?php

namespace Storyfeed\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * An inferred burst of activity by one actor — travnett2's open-window
 * pattern generalized. Opened implicitly on publish, closed by inactivity
 * (lazily at the actor's next publish, or promptly by storyfeed:close-batches).
 *
 * Batches are INFRASTRUCTURE: recorded and queryable, with BatchClosed as
 * the digest hook. They deliberately do not participate in feed grouping
 * yet — whether a session batch should win a feed view is an open problem
 * (docs/grouping.md), distinct from composite activities.
 *
 * Membership rides the grouping substrate: one feed_groupings row per
 * member with bucket = 'batch', hash = this batch's uid.
 *
 * @property int $id
 * @property string $uid
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property Carbon $opened_at
 * @property Carbon|null $closed_at
 * @property int $activities_count
 * @property Carbon|null $last_activity_at
 * @property array<array-key, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Batch extends Model
{
    use HasUlids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function getTable(): string
    {
        return config('storyfeed.tables.batches', 'feed_batches');
    }

    public function uniqueIds(): array
    {
        return ['uid'];
    }

    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasManyThrough<Activity, Grouping, $this>
     */
    public function activities(): HasManyThrough
    {
        /** @var HasManyThrough<Activity, Grouping, $this> */
        return $this->hasManyThrough(
            config('storyfeed.models.activity', Activity::class),
            config('storyfeed.models.grouping', Grouping::class),
            firstKey: 'hash',
            secondKey: 'id',
            localKey: 'uid',
            secondLocalKey: 'activity_id',
        )->where('bucket', 'batch');
    }

    /**
     * @param  Builder<static>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('closed_at');
    }

    /**
     * Role columns store morph aliases — compare with getMorphClass(),
     * never get_class().
     *
     * @param  Builder<static>  $query
     */
    public function scopeForActor(Builder $query, Model $actor): void
    {
        $query->where('actor_type', $actor->getMorphClass())
            ->where('actor_id', $actor->getKey());
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }
}
