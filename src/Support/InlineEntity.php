<?php

namespace Storyfeed\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use Storyfeed\Body\Image;
use Storyfeed\Body\MediaObject;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\DeferredMedia;
use Storyfeed\FeedEntity;
use Storyfeed\FeedImage;
use Storyfeed\FeedMedia;
use Storyfeed\Models\Snapshot;

/**
 * A role with no model behind it: `['type' => 'vote', 'label' => 'their vote']`,
 * optionally with a `url`, an `id`, `data`, a `body` and `media`. The same
 * shape the array source reads, accepted on the write path too.
 *
 * `media` is the entity's pictures and avatar, as a model's `feedMedia()`
 * gives them: `icon`, `image` and `preview` (each a URL, a {@see FeedImage},
 * or its array form `['src' => …, 'alt' => …, 'width' => …, 'height' => …]`),
 * and `initials` and `color` for an avatar with no picture.
 *
 *     ['type' => 'product', 'label' => 'InvoiceJam', 'media' => ['icon' => 'https://…/invoicejam.svg']]
 *
 * It is stored inline with the activity, in its `entities` column, and its
 * type and id in the role's own columns. Nothing hydrates or refreshes it:
 * the label it was recorded with is the label it keeps. An id makes it a
 * participant `involving()` can find; without one it is in no index.
 */
final class InlineEntity
{
    /** The keys an entity array may carry. */
    public const KEYS = ['type', 'label', 'url', 'id', 'data', 'body', 'media'];

    /** The keys an entity's `media` may carry. */
    public const MEDIA_KEYS = ['icon', 'image', 'preview', 'initials', 'color'];

    /** The keys an image in `media` may carry, as {@see FeedImage::toArray()} writes them. */
    protected const IMAGE_KEYS = ['src', 'mediaType', 'width', 'height', 'alt'];

    /**
     * @param  array<array-key, mixed>  $entity
     *
     * @throws InvalidArgumentException naming the role and the key at fault
     */
    public static function assert(string $role, array $entity): void
    {
        if (($unknown = array_diff(array_keys($entity), self::KEYS)) !== []) {
            throw new InvalidArgumentException(sprintf(
                'Unknown key [%s] on the [%s] entity. Entities take: %s.', implode(', ', $unknown), $role, implode(', ', self::KEYS),
            ));
        }

        foreach (['type', 'label'] as $required) {
            if (! is_string($entity[$required] ?? null) || $entity[$required] === '') {
                throw new InvalidArgumentException("The [{$role}] entity needs a [{$required}].");
            }
        }

        if (isset($entity['url']) && ! is_string($entity['url'])) {
            throw new InvalidArgumentException("The [{$role}] entity's [url] must be a string.");
        }

        self::media($role, $entity['media'] ?? null);
    }

    /**
     * The entity's `media` as the payload carries it, or null when it
     * declares none: the same object a model's `feedMedia()` produces.
     *
     * @return array<string, mixed>|null
     *
     * @throws InvalidArgumentException naming the role and the key at fault
     */
    public static function media(string $role, mixed $media): ?array
    {
        if ($media === null) {
            return null;
        }

        if (! is_array($media)) {
            throw new InvalidArgumentException(sprintf(
                "The [%s] entity's [media] must be an array of %s; %s given.", $role, implode(', ', self::MEDIA_KEYS), get_debug_type($media),
            ));
        }

        if (($unknown = array_diff(array_keys($media), self::MEDIA_KEYS)) !== []) {
            throw new InvalidArgumentException(sprintf(
                "Unknown key [%s] in the [%s] entity's [media]. It takes: %s.", implode(', ', $unknown), $role, implode(', ', self::MEDIA_KEYS),
            ));
        }

        foreach (['initials', 'color'] as $text) {
            if (isset($media[$text]) && ! is_string($media[$text])) {
                throw new InvalidArgumentException("The [{$role}] entity's [media.{$text}] must be a string.");
            }
        }

        return FeedMedia::make(
            icon: self::image($role, 'icon', $media['icon'] ?? null),
            preview: self::image($role, 'preview', $media['preview'] ?? null),
            image: self::image($role, 'image', $media['image'] ?? null),
            initials: $media['initials'] ?? null,
            color: $media['color'] ?? null,
        )->media();
    }

    /**
     * Refuse a body that shows one of the entity's media slots when the
     * entity declares no picture there: with no model behind it, nothing
     * else could fill the slot, and the body would draw nothing.
     *
     * A party name (`'Storyfeed'`) declares no pictures at all, so any slot
     * it is asked to show is empty: pass the name as `$party`.
     *
     * @param  list<array<string, mixed>>  $body
     * @param  array<string, mixed>|null  $media  the entity's media, from {@see media()}
     *
     * @throws InvalidArgumentException naming the slot and both ways out
     */
    public static function assertSlots(string $role, array $body, ?array $media, ?string $party = null): void
    {
        foreach ($body as $form) {
            if (! in_array($form[FeedBody::KEY] ?? null, [Image::bodyType(), MediaObject::bodyType()], true)) {
                continue;
            }

            $slot = DeferredMedia::tryFromPayload($form['image'] ?? null)?->value;

            if ($slot === null || (! str_starts_with($slot, 'slots.') && ($media[$slot] ?? null) !== null)) {
                continue;
            }

            if ($party !== null) {
                throw new InvalidArgumentException(sprintf(
                    'A %s body on the [%s] party [%s] shows its [%s] picture, but a party name declares no pictures, so it would draw nothing. '
                    .'Pass an entity array that declares it ([\'type\' => …, \'label\' => %s, \'media\' => [\'%s\' => $url]]), or give the body its own picture (FeedImage::make()->src($url)).',
                    class_basename($form[FeedBody::KEY]), $role, $party, $slot, var_export($party, true), str_starts_with($slot, 'slots.') ? 'image' : $slot,
                ));
            }

            $custom = str_starts_with($slot, 'slots.');

            throw new InvalidArgumentException(sprintf(
                'A %s body on the [%s] entity shows its [%s] picture, but the entity has no model behind it and %s, so it would draw nothing. '
                .'%s, or give the body its own picture (FeedImage::make()->src($url)).',
                class_basename($form[FeedBody::KEY]), $role, $slot,
                $custom ? 'only declares icon, image and preview' : 'declares none',
                $custom ? 'Show one of those, declared on the entity ([\'media\' => [\'image\' => $url]])' : "Declare it on the entity (['media' => ['{$slot}' => \$url]])",
            ));
        }
    }

    /** @throws InvalidArgumentException when it is not an image */
    protected static function image(string $role, string $slot, mixed $image): FeedImage|string|null
    {
        if ($image === null || $image instanceof FeedImage) {
            return $image;
        }

        if (is_string($image) && $image !== '') {
            return $image;
        }

        if (is_array($image) && is_string($image['src'] ?? null) && $image['src'] !== '' && array_diff(array_keys($image), self::IMAGE_KEYS) === []) {
            $int = fn (string $key): ?int => is_int($image[$key] ?? null) ? $image[$key] : null;
            $text = fn (string $key): ?string => is_string($image[$key] ?? null) ? $image[$key] : null;

            return FeedImage::make(src: $image['src'], mediaType: $text('mediaType'), width: $int('width'), height: $int('height'), alt: $text('alt'));
        }

        throw new InvalidArgumentException(sprintf(
            "The [%s] entity's [media.%s] must be a URL, a FeedImage, or an array with a [src] and optionally %s.",
            $role, $slot, implode(', ', array_slice(self::IMAGE_KEYS, 1)),
        ));
    }

    /**
     * The entity as it is stored: what the snapshot is rebuilt from at read
     * time. The id is the role's `{role}_id`, so it must fit that column.
     *
     * @param  array<array-key, mixed>  $entity
     * @return array{type: string, id: string|null, label: string, url?: string, data?: array<string, mixed>, body?: list<array<string, mixed>>, media?: array<string, mixed>}
     *
     * @throws InvalidArgumentException when it is not an entity, or its id does not fit
     */
    public static function store(string $role, array $entity): array
    {
        self::assert($role, $entity);

        $id = $entity['id'] ?? null;

        if ($id !== null && (! is_scalar($id) || preg_match('/^[\x21-\x7E]{1,36}$/', (string) $id) !== 1)) {
            throw new InvalidArgumentException("The [{$role}] entity's [id] must be up to 36 printable ASCII characters, as a morph key is.");
        }

        $snapshot = FeedEntity::make(label: $entity['label'], data: $entity['data'] ?? [], body: $entity['body'] ?? null);

        return array_filter([
            'type' => $entity['type'],
            'id' => $id === null ? null : (string) $id,
            'label' => $entity['label'],
            'url' => $entity['url'] ?? null,
            'data' => $snapshot->data,
            'body' => $snapshot->body,
            'media' => self::media($role, $entity['media'] ?? null),
        ], fn (mixed $value, string $key) => in_array($key, ['type', 'id', 'label'], true) || ($value !== null && $value !== []), ARRAY_FILTER_USE_BOTH);
    }

    /** @var array<string, bool> */
    private static array $installed = [];

    /**
     * Whether the activities table has the `entities` column, by connection.
     * A query that looks inside it checks first, so an app that has not run
     * the migration reads as before.
     */
    public static function installed(Model $activity): bool
    {
        $key = $activity->getConnectionName().'|'.$activity->getTable();

        return self::$installed[$key] ??= $activity->getConnection()->getSchemaBuilder()->hasColumn($activity->getTable(), 'entities');
    }

    /**
     * Leave out the rows whose `$role` is an inline entity, for a query that
     * asks about the models behind a role.
     *
     * @template TBuilder of Builder<*>|QueryBuilder
     *
     * @param  TBuilder  $query
     * @return TBuilder
     */
    public static function exclude(Builder|QueryBuilder $query, Model $activity, string $role): Builder|QueryBuilder
    {
        return self::installed($activity) ? $query->whereNull("entities->{$role}") : $query;
    }

    /**
     * The snapshot a stored entity reads as: never saved, never refreshed.
     *
     * @param  array<array-key, mixed>  $stored
     */
    public static function snapshot(array $stored): Snapshot
    {
        $model = config('storyfeed.models.snapshot', Snapshot::class);

        return (new $model)->forceFill([
            'model_type' => (string) ($stored['type'] ?? ''),
            'model_id' => isset($stored['id']) ? (string) $stored['id'] : null,
            'label' => is_string($stored['label'] ?? null) ? $stored['label'] : null,
            'data' => is_array($stored['data'] ?? null) ? $stored['data'] : [],
            'body' => is_array($stored['body'] ?? null) && $stored['body'] !== [] ? $stored['body'] : null,
            'meta' => self::meta(is_string($stored['url'] ?? null) ? $stored['url'] : null, is_array($stored['media'] ?? null) ? $stored['media'] : null),
        ]);
    }

    /**
     * A snapshot's `meta` for an entity with no model behind it: its href,
     * and its media, which the payload reads when no resolver gives any.
     *
     * @param  array<string, mixed>|null  $media
     * @return array<string, mixed>
     */
    public static function meta(?string $url, ?array $media): array
    {
        return array_filter(['url' => $url, 'media' => $media], fn (mixed $value) => $value !== null);
    }
}
