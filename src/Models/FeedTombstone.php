<?php

namespace Storyfeed\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Contracts\HasActivityStreamsType;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedMedia;

/**
 * What a deleted entity leaves behind in the feed: one row per deleted model,
 * keyed by its morph alias and key.
 *
 * When a Feedable model is deleted, every activity that named it, in any of
 * the seven roles, is repointed here, and the model's own snapshot is
 * dropped. The stories survive, and no trace of the model's key or label is
 * left on them. A restore repoints them back and discards the tombstone; a
 * hard delete leaves the tombstone as the permanent reference.
 *
 * A Feedable like Party: package-owned, with a morph alias that resolves
 * through Support\MorphResolver whatever the app's morph map says. Its label
 * is null unless something asked to keep one, as Activity Streams 2.0 asks
 * of a Tombstone ("remove most of the properties").
 *
 * @property int $id
 * @property string $model_type the deleted model's morph alias
 * @property string $model_id the deleted model's key, as a string
 * @property bool $restorable false once the model is gone for good
 * @property bool $approximate true when the trickle found the deletion, so `deleted_at` is when it was found
 * @property Carbon|null $deleted_at
 * @property string|null $label
 * @property array<array-key, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FeedTombstone extends Model implements Feedable, HasActivityStreamsType
{
    /** The morph alias every tombstone reference is stored under. */
    public const MORPH_ALIAS = 'storyfeed.tombstone';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'restorable' => 'boolean',
            'approximate' => 'boolean',
            'deleted_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function getTable(): string
    {
        return config('storyfeed.tables.tombstones', 'feed_tombstones');
    }

    /**
     * Resolved independently of the application's morph map, so apps calling
     * Relation::enforceMorphMap() cannot break package models.
     */
    public function getMorphClass(): string
    {
        return self::MORPH_ALIAS;
    }

    /** The tombstone for a model's alias and key, if it has one. */
    public static function for(string $type, int|string $id): ?static
    {
        return static::query()->where('model_type', $type)->where('model_id', (string) $id)->first();
    }

    /** The deleted model's morph alias: AS2's `formerType`, in the app's own words. */
    public function formerType(): string
    {
        return $this->model_type;
    }

    /**
     * When the model was deleted, or null when nobody knows. For a deletion
     * the trickle found, this is when it was found (see isApproximate()).
     * An interface, because an app on `Date::use(CarbonImmutable::class)`
     * gets an immutable date from the cast.
     */
    public function deletedAt(): ?CarbonInterface
    {
        return $this->deleted_at;
    }

    /** Whether deletedAt() is when the deletion was found rather than when it happened. */
    public function isApproximate(): bool
    {
        return (bool) $this->approximate;
    }

    /**
     * The tombstone's entry on a payload entity (docs/payload.md,
     * `entity.tombstone`). `removedBy` is reserved: who removed it, as a
     * relation and never a name, which redaction will fill.
     *
     * @return array{formerType: string, deleted: string|null, approximate: bool, removedBy: null}
     */
    public function toPayload(): array
    {
        return [
            'formerType' => $this->formerType(),
            'deleted' => $this->deletedAt()?->toISOString(),
            'approximate' => $this->isApproximate(),
            'removedBy' => null,
        ];
    }

    public function toFeed(): FeedEntity
    {
        return FeedEntity::make(label: $this->label);
    }

    /** A removed entity has nowhere to link to. */
    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        return null;
    }

    public static function activityStreamsType(): ObjectType
    {
        return ObjectType::Tombstone;
    }
}
