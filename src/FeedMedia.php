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
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public private(set) FeedImage|string|null $url = null,
        public private(set) ?string $label = null,
        public private(set) array $attributes = [],
        public private(set) bool $modal = false,
        public private(set) ?FeedImage $icon = null,
        public private(set) ?FeedImage $preview = null,
        public private(set) ?FeedImage $image = null,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function make(
        FeedImage|string|null $url = null,
        ?string $label = null,
        array $attributes = [],
        bool $modal = false,
        FeedImage|string|null $icon = null,
        FeedImage|string|null $preview = null,
        FeedImage|string|null $image = null,
    ): self {
        return new self(
            $url,
            $label,
            $attributes,
            $modal,
            $icon === null ? null : FeedImage::from($icon),
            $preview === null ? null : FeedImage::from($preview),
            $image === null ? null : FeedImage::from($image),
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
     * The four slots as the payload carries them, or null when none is set.
     *
     * Null rather than four nulls so "does this entity have media at all" is
     * one check, the same one `url: null` answers for linkability. When it is
     * an object every slot key is present, so a renderer that wants one slot
     * reads it without first asking which slots exist. `url` here is the
     * typed form only: a string url is not media and appears solely as
     * `entity.url`.
     *
     * @return array{icon: array<string, mixed>|null, image: array<string, mixed>|null, preview: array<string, mixed>|null, url: array<string, mixed>|null}|null
     */
    public function media(): ?array
    {
        $slots = [
            'icon' => $this->icon,
            'image' => $this->image,
            'preview' => $this->preview,
            'url' => $this->url instanceof FeedImage ? $this->url : null,
        ];

        if (array_filter($slots) === []) {
            return null;
        }

        return array_map(fn (?FeedImage $image) => $image?->toArray(), $slots);
    }
}
