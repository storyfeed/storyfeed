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
use Storyfeed\FeedImage;
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
     *
     * The external home is stored in the reserved `$url` data slot and
     * follows snapshot updates. Omitting url preserves it, even when data
     * is replaced; passing url: null explicitly removes it.
     *
     * @param  array<string, mixed>  $data
     */
    public static function make(
        string $name,
        ?string $key = null,
        ObjectType|string $type = ObjectType::Service,
        array $data = [],
        ?string $url = null,
    ): static {
        $key ??= Str::slug($name);

        $party = static::query()->firstOrNew(['key' => $key]);

        $party->name = $name;
        $party->type = $type instanceof ObjectType ? $type->value : $type;

        $stored = $party->data ?? [];

        if ($data !== []) {
            // Replacing app data must not silently remove the external home.
            $party->data = array_key_exists('$url', $stored)
                ? array_replace(['$url' => $stored['$url']], $data)
                : $data;
        }

        // PHP counts explicitly supplied named arguments too. Distinguish
        // url: null (remove) from an omitted url (keep on rename/string use).
        if (func_num_args() >= 5) {
            $party->data = array_replace($party->data ?? [], ['$url' => $url]);
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
     * Parties have no host record, but may have an external home in `$url`.
     * Both links and pictures resolve from snapshots without a Party query.
     * A picture represents a participant without claiming a host record exists.
     * `$media` accepts icon, preview and image slots: non-empty source strings
     * or FeedImage-shaped arrays. It also accepts the text avatar, `initials`
     * and `color`, as strings. Absent or malformed media degrades to null.
     * Written out rather than taken from InteractsWithFeed because Party does not use
     * the trait: it keeps its own saved hook and deliberately no delete
     * cascade (history outlives a retired integration).
     */
    public static function feedMedia(FeedContext $context): ?FeedMedia
    {
        $url = $context->data('$url');
        $link = is_string($url) && trim($url) !== '' ? FeedMedia::make(url: $url) : null;
        $data = $context->data('$media');

        if (! is_array($data) || $data === [] || array_diff(array_keys($data), ['icon', 'preview', 'image', 'initials', 'color']) !== []) {
            return $link;
        }

        foreach (['initials', 'color'] as $field) {
            if (isset($data[$field]) && ! is_string($data[$field])) {
                return $link;
            }
        }

        $media = FeedMedia::make(url: $link?->href(), initials: $data['initials'] ?? null, color: $data['color'] ?? null);

        foreach (['icon', 'preview', 'image'] as $slot) {
            $value = $data[$slot] ?? null;
            if ($value === null) {
                continue;
            }

            if (is_string($value)) {
                $value = ['src' => $value];
            }

            if (! is_array($value) || ! is_string($value['src'] ?? null) || trim($value['src']) === '') {
                return $link;
            }

            if (array_diff(array_keys($value), ['src', 'mediaType', 'width', 'height', 'alt']) !== []) {
                return $link;
            }

            foreach (['mediaType', 'alt'] as $field) {
                if (isset($value[$field]) && ! is_string($value[$field])) {
                    return $link;
                }
            }

            foreach (['width', 'height'] as $field) {
                if (isset($value[$field]) && ! is_int($value[$field])) {
                    return $link;
                }
            }

            $image = FeedImage::make(
                src: $value['src'],
                mediaType: $value['mediaType'] ?? null,
                width: $value['width'] ?? null,
                height: $value['height'] ?? null,
                alt: $value['alt'] ?? null,
            );

            match ($slot) {
                'icon' => $media->icon($image),
                'preview' => $media->preview($image),
                'image' => $media->image($image),
            };
        }

        return $media->href() === null && $media->media() === null ? null : $media;
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
