<?php

namespace Storyfeed\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Storyfeed\Actions\SnapshotEntity;
use Storyfeed\ActivityStreams\ObjectType;
use Storyfeed\Contracts\Feedable;
use Storyfeed\Contracts\HasActivityStreamsType;
use Storyfeed\FeedContext;
use Storyfeed\FeedEntity;
use Storyfeed\FeedMedia;

/**
 * A named participant that lives only in the feed: an external system, the
 * application acting on its own behalf, a legacy import.
 *
 *   Party::make('Concur Web Service')
 *   Storyfeed::record($verb, object: $profile, target: 'Concur');
 *
 * Usable in any role — the same system is an actor in one story and a target
 * in another. Distinct from a NULL actor, which means genuinely unknown.
 *
 * `key` is the identity; `name` is display. Renaming is therefore free:
 * Party::make('Platform', key: 'system') renames the row and every activity
 * follows.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $type
 * @property array<array-key, mixed>|null $data
 */
class Party extends Model implements Feedable, HasActivityStreamsType
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
        return config('storyfeed.tables.parties', 'feed_parties');
    }

    /**
     * Resolved independently of the application's morph map so that apps
     * calling Relation::enforceMorphMap() cannot break package models.
     */
    public function getMorphClass(): string
    {
        return config('storyfeed.morph_alias', 'storyfeed.party');
    }

    protected static function booted(): void
    {
        // Renames propagate to every activity via the snapshot. Deliberately
        // no `deleted` cascade: history outlives a retired integration.
        static::saved(function (self $party) {
            (new SnapshotEntity)($party);
        });
    }

    /**
     * Resolve or create a party by key (slugged from the name by default).
     */
    public static function make(
        string $name,
        ?string $key = null,
        ObjectType|string $type = ObjectType::Service,
        array $data = [],
    ): static {
        $key ??= Str::slug($name);

        $party = static::query()->firstOrNew(['key' => $key]);

        $party->name = $name;
        $party->type = $type instanceof ObjectType ? $type->value : $type;

        if ($data !== []) {
            $party->data = $data;
        }

        if ($party->isDirty() || ! $party->exists) {
            $party->save();
        }

        return $party;
    }

    /**
     * Look up a party without creating one. Used by the read path, where a
     * query must never write.
     */
    public static function find(string $key): ?static
    {
        return static::query()->where('key', $key)->first()
            ?? static::query()->where('key', Str::slug($key))->first();
    }

    public function toFeed(): FeedEntity
    {
        return FeedEntity::make(
            label: $this->name,
            data: array_merge($this->data ?? [], [
                'key' => $this->key,
                'type' => $this->type,
            ]),
        );
    }

    /**
     * Parties have no canonical URL in the host application. Written out
     * rather than taken from InteractsWithFeed because Party does not use
     * the trait: it keeps its own saved hook and deliberately no delete
     * cascade (history outlives a retired integration).
     */
    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        return null;
    }

    /**
     * The class-level default. Individual rows carry their own `type`, which
     * rides in the snapshot data for the serializer to prefer.
     */
    public static function activityStreamsType(): ObjectType
    {
        return ObjectType::Service;
    }
}
