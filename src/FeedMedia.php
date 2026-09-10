<?php

namespace Storyfeed;

/**
 * What a resolver knows about an entity at read time that its snapshot
 * cannot cache: a fresh url, an optional label override, link attributes,
 * a modal hint, and the images that go with it. Returned by
 * Feedable::feedMedia().
 *
 * Replaced FeedLink, which stopped being "a link" once it grew a label and
 * a modal flag, and which was removed with the older toFeedLink() contract
 * on 2026-09-05 (journal 057). Part of the versioned payload contract.
 *
 * ## The slots are AS2's property names, and the slot IS the meaning
 *
 *   icon     small and representational, ~32×32, 1:1 — an avatar, a logo
 *   image    a larger visual representation of a non-image object — a hero
 *            shot on a recipe
 *   preview  a preview of the resource — the dense-feed thumbnail
 *   url      where the resource itself lives — for a photo, the full image
 *
 * A single anonymous "media" field was rejected because it would have made
 * every renderer guess from the role what the picture was FOR. AS2 already
 * made the distinctions a renderer needs, in particular the one a photo
 * object turns on: `url` is the resource and `preview` is the derivative you
 * paint in a list. Honour it rather than invent it.
 *
 * So `url` accepts a FeedImage as well as a string. As a string it is what
 * it always was, the href a tap follows. As a FeedImage it is still that
 * href (href() reads either) and ALSO says the thing at the other end is an
 * image with these dimensions — which is what lets the payload carry
 * `media.url` and the AS2 serializer emit `url` as a Link with `mediaType`,
 * `width` and `height`.
 *
 * Non-image resources are `attachments`, a list: each FeedResource carries
 * AS2's href, mediaType and name with a Document (or extension) object type,
 * and the list keeps the order it was given. AS2's `attachment` is
 * one-or-many, so a block listing four files gets four live hrefs, minted
 * the way FeedImage::src is. Empty by default; the image slots retain
 * their meaning and `url` stays image-only.
 *
 * ## Two ways to build one, both wanted
 *
 *     FeedMedia::make(url: $full, preview: $thumb)
 *     FeedMedia::make($href)->preview($thumb)->icon($avatar)
 *
 * Named arguments for the one-expression case; fluent setters for the
 * resolver that decides slot by slot. The setters mutate and return $this,
 * as StoryDefinition's do, and the properties are `private(set)` so the
 * value is still immutable from outside: a presenter can read every slot and
 * change none.
 */
final class FeedMedia
{
    /** @var list<FeedResource> */
    public private(set) array $attachments;

    /**
     * @param  array<string, mixed>  $attributes
     * @param  iterable<FeedResource>  $attachments
     */
    public function __construct(
        public private(set) FeedImage|string|null $url = null,
        public private(set) ?string $label = null,
        public private(set) array $attributes = [],
        public private(set) bool $modal = false,
        public private(set) ?FeedImage $icon = null,
        public private(set) ?FeedImage $preview = null,
        public private(set) ?FeedImage $image = null,
        iterable $attachments = [],
    ) {
        $this->attachments = self::resources($attachments);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  iterable<FeedResource>  $attachments
     */
    public static function make(
        FeedImage|string|null $url = null,
        ?string $label = null,
        array $attributes = [],
        bool $modal = false,
        FeedImage|string|null $icon = null,
        FeedImage|string|null $preview = null,
        FeedImage|string|null $image = null,
        iterable $attachments = [],
    ): self {
        return new self(
            $url,
            $label,
            $attributes,
            $modal,
            $icon === null ? null : FeedImage::from($icon),
            $preview === null ? null : FeedImage::from($preview),
            $image === null ? null : FeedImage::from($image),
            $attachments,
        );
    }

    /**
     * Media whose link should open as a modal.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function modal(FeedImage|string|null $url = null, ?string $label = null, array $attributes = []): self
    {
        return new self($url, $label, $attributes, modal: true);
    }

    public function url(FeedImage|string|null $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * Replace the attachment list. Order is kept as given: the payload and
     * the AS2 document both emit it in this sequence.
     *
     * @param  iterable<FeedResource>  $attachments
     */
    public function attachments(iterable $attachments): self
    {
        $this->attachments = self::resources($attachments);

        return $this;
    }

    /**
     * A list, whatever iterable arrived, with every element checked to be a
     * FeedResource — the closure's parameter type makes a stray string a
     * TypeError at the call site rather than a broken node at read time.
     *
     * @param  iterable<FeedResource>  $attachments
     * @return list<FeedResource>
     */
    private static function resources(iterable $attachments): array
    {
        return array_map(
            static fn (FeedResource $resource): FeedResource => $resource,
            array_values(is_array($attachments) ? $attachments : iterator_to_array($attachments, false)),
        );
    }

    public function icon(FeedImage|string|null $icon): self
    {
        $this->icon = $icon === null ? null : FeedImage::from($icon);

        return $this;
    }

    public function preview(FeedImage|string|null $preview): self
    {
        $this->preview = $preview === null ? null : FeedImage::from($preview);

        return $this;
    }

    public function image(FeedImage|string|null $image): self
    {
        $this->image = $image === null ? null : FeedImage::from($image);

        return $this;
    }

    /**
     * The href, whichever form `url` took. Readers that only want somewhere
     * to point a tap — the payload's `entity.url`, the AS2 actor `id` — read
     * this and never learn whether the resource was an image.
     */
    public function href(): ?string
    {
        return $this->url instanceof FeedImage ? $this->url->src : $this->url;
    }

    /**
     * The image slots and the attachment list, or null when nothing is set.
     *
     * Null rather than four nulls and an empty list so "does this entity
     * have media at all" is one check, the same one `url: null` answers for
     * linkability. When it is an object every key is present — the four
     * image slots as an image object or null, `attachments` as a list that
     * may be empty — so a renderer that wants one slot reads it without
     * first asking which slots exist. `url` here is the typed form only: a
     * string url is not media and appears solely as `entity.url`.
     *
     * @return array{icon: array<string, mixed>|null, image: array<string, mixed>|null, preview: array<string, mixed>|null, url: array<string, mixed>|null, attachments: list<array<string, mixed>>}|null
     */
    public function media(): ?array
    {
        $images = [
            'icon' => $this->icon,
            'image' => $this->image,
            'preview' => $this->preview,
            'url' => $this->url instanceof FeedImage ? $this->url : null,
        ];

        if (array_filter($images) === [] && $this->attachments === []) {
            return null;
        }

        return [
            ...array_map(fn (?FeedImage $image) => $image?->toArray(), $images),
            'attachments' => array_map(fn (FeedResource $resource) => $resource->toArray(), $this->attachments),
        ];
    }
}
