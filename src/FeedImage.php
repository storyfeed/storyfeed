<?php

namespace Storyfeed;

use Illuminate\Support\Traits\Conditionable;
use Storyfeed\Exceptions\IncompleteFeedValue;

/**
 * One image in one of FeedMedia's slots: where it is and what a renderer
 * needs to know before it has fetched it.
 *
 *     FeedImage::make()
 *         ->src($media->getUrl('thumb'))
 *         ->mediaType($context->data('mediaType'))
 *         ->width($context->data('width'))
 *         ->height($context->data('height'))
 *         ->alt($context->label())
 *
 *     FeedImage::make(
 *         src: $media->getUrl('thumb'),
 *         mediaType: $context->data('mediaType'),
 *         width: $context->data('width'),
 *         height: $context->data('height'),
 *         alt: $context->label(),
 *     )
 *
 * ## The src is resolved, never stored
 *
 * Same rule as the entity's url: a URL copied into a snapshot ages (disks
 * move, signed links expire), so the snapshot carries the intrinsic facts —
 * mediaType, width, height — and the location is resolved from them at
 * read time. That is why this class is what feedMedia() RETURNS and not
 * what toFeed() stores.
 *
 * ## Field names are AS2's where AS2 has one
 *
 * `mediaType`, `width`, `height` are the properties of an AS2 `Link`, spelled
 * as the spec spells them, because the serializer copies them onto the wire
 * verbatim and a renderer copies them into an aspect reservation verbatim; a
 * rename in between would be a translation layer that earns nothing.
 *
 * The two exceptions are the two a renderer hands straight to `<img>`: `src`
 * and `alt`. AS2 says `href` and `name` for the same two facts, and the
 * serializer spells them that way at the boundary — the one place the
 * spelling matters. Neither is an extension term; both have an AS2 word.
 *
 * ## Width and height are advisory, and optional
 *
 * They exist so a dense feed can reserve the box before the bytes arrive
 * and not shift on load. A dimension the resolver does not know is null, not
 * zero — zero would reserve nothing and look like a fact. Non-positive
 * values are degraded to null for the same reason `Body\FileAttachment` degrades a
 * negative size: an impossible measurement is a missing one.
 *
 * Part of the versioned payload contract (docs/payload.md, `entity.media`).
 */
final class FeedImage
{
    use Conditionable;

    /**
     * Where the image is, resolved at read time. Required: reading it before
     * `src()` is called throws, naming the method.
     */
    public private(set) string $src {
        get => $this->src ?? throw IncompleteFeedValue::missing(self::class, 'src');
    }

    public private(set) ?string $mediaType = null;

    public private(set) ?int $width = null;

    public private(set) ?int $height = null;

    public private(set) ?string $alt = null;

    public function __construct(
        ?string $src = null,
        ?string $mediaType = null,
        ?int $width = null,
        ?int $height = null,
        ?string $alt = null,
    ) {
        if ($src !== null) {
            $this->src($src);
        }

        $this->mediaType($mediaType)
            ->width($width)
            ->height($height)
            ->alt($alt);
    }

    /**
     * Start an image. Every argument is optional and has a method of the same
     * name; `src` must be set before the image is used.
     */
    public static function make(
        ?string $src = null,
        ?string $mediaType = null,
        ?int $width = null,
        ?int $height = null,
        ?string $alt = null,
    ): self {
        return new self($src, $mediaType, $width, $height, $alt);
    }

    /**
     * A bare URL is an image whose dimensions nobody knows yet. Accepting it
     * everywhere a FeedImage is accepted keeps the one-line case one line.
     */
    public static function from(self|string $image): self
    {
        return $image instanceof self ? $image : new self($image);
    }

    /** Where the image is: the url a renderer hands to `<img src>`. */
    public function src(string $src): self
    {
        $this->src = $src;

        return $this;
    }

    /** The image's MIME type, such as `image/webp`. */
    public function mediaType(?string $mediaType): self
    {
        $this->mediaType = $mediaType;

        return $this;
    }

    /** The intrinsic width in pixels. Unknown, zero or negative is null. */
    public function width(?int $width): self
    {
        $this->width = $width !== null && $width > 0 ? $width : null;

        return $this;
    }

    /** The intrinsic height in pixels. Unknown, zero or negative is null. */
    public function height(?int $height): self
    {
        $this->height = $height !== null && $height > 0 ? $height : null;

        return $this;
    }

    /** Text for a reader who cannot see the image. */
    public function alt(?string $alt): self
    {
        $this->alt = $alt;

        return $this;
    }

    /**
     * The payload shape. Every key is always present, so a renderer reads a
     * missing dimension as null rather than as an undefined index.
     *
     * @return array{src: string, mediaType: string|null, width: int|null, height: int|null, alt: string|null}
     */
    public function toArray(): array
    {
        return [
            'src' => $this->src,
            'mediaType' => $this->mediaType,
            'width' => $this->width,
            'height' => $this->height,
            'alt' => $this->alt,
        ];
    }
}
