<?php

namespace Storyfeed\Support;

/**
 * Every entity has an avatar (#92): its declared `icon`, else its declared
 * `initials` on its declared `color`, else initials derived from its label on
 * a colour derived from its identity. A kit never draws a blank.
 *
 * The colour is the same for the same entity on every page and in every kit:
 * the morph alias and key are hashed onto {@see PALETTE}, so the palette's
 * order is part of the payload contract. A tombstone, and an entity with no
 * label, take {@see NEUTRAL}; with no label the initials are `?`.
 *
 * Read-time only, like the rest of `media`, and never on the Activity
 * Streams wire.
 */
final class Avatar
{
    /** Mid-tone colours that carry white or dark text. Append only: an index is a colour. */
    public const PALETTE = [
        '#c2410c', '#b45309', '#4d7c0f', '#15803d', '#0f766e', '#0e7490',
        '#1d4ed8', '#4338ca', '#7e22ce', '#a21caf', '#be123c', '#9f1239',
    ];

    public const NEUTRAL = '#6b7280';

    /** The media object of an entity that declared none. */
    public const EMPTY = ['icon' => null, 'image' => null, 'preview' => null, 'initials' => null, 'color' => null, 'files' => [], 'slots' => []];

    /**
     * The media object with the avatar filled in: the given one, or an empty
     * one when the entity declared no media.
     *
     * @param  array<string, mixed>|null  $media
     * @return array<string, mixed>
     */
    public static function fill(?array $media, string $type, ?string $id, ?string $label, bool $tombstone = false): array
    {
        $media ??= self::EMPTY;

        if ($media['icon'] !== null) {
            return $media;
        }

        $label = $label === null ? '' : trim($label);

        $media['initials'] ??= self::initials($label);
        $media['color'] ??= $tombstone || $label === '' ? self::NEUTRAL : self::color($type, $id ?? $label);

        return $media;
    }

    /**
     * The first letter of the first word and of the last, uppercase: `Acme
     * Co` is `AC`, `Acme` is `A`. A word's first letter or digit counts, so
     * `@ana` is `A`. No label, or nothing to take, is `?`.
     */
    public static function initials(?string $label): string
    {
        $words = array_values(array_filter(
            preg_split('/\s+/u', trim((string) $label)) ?: [],
            fn (string $word) => preg_match('/[\p{L}\p{N}]/u', $word) === 1,
        ));

        if ($words === []) {
            return '?';
        }

        $first = fn (string $word): string => preg_match('/[\p{L}\p{N}]/u', $word, $letter) === 1 ? $letter[0] : '';

        $initials = $first($words[0]).(count($words) > 1 ? $first($words[count($words) - 1]) : '');

        return mb_strtoupper($initials);
    }

    /** The palette colour for an identity: the same alias and key, the same colour. */
    public static function color(string $type, string $key): string
    {
        return self::PALETTE[crc32($type."\0".$key) % count(self::PALETTE)];
    }
}
