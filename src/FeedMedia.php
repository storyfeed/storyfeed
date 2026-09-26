<?php

namespace Storyfeed;

use Closure;
use Illuminate\Support\Traits\Conditionable;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\Support\BodySlot;
use Throwable;

/**
 * What a resolver knows about an entity at read time that its snapshot
 * cannot cache: a fresh url, an optional label override, link attributes,
 * a modal hint, and the images that go with it. Returned by
 * Feedable::feedMedia().
 *
 * Replaced the first FeedLink, which stopped being "a link" once it grew a
 * label and a modal flag, and was removed with the older toFeedLink()
 * contract on 2026-09-05 (journal 057). The name now belongs to
 * {@see FeedLink}, a label and an optional href inside bodies. Part of the
 * versioned payload contract.
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
 * one-or-many, so a block listing four files gets four live hrefs, resolved
 * the way FeedImage::src is. Empty by default; the image slots retain
 * their meaning and `url` stays image-only.
 *
 * ## Two ways to build one, both wanted
 *
 *     FeedMedia::make(url: $full, preview: $thumb)
 *     FeedMedia::make()->url($full)->preview($thumb)->icon($avatar)
 *
 * Named arguments for the one-expression case; fluent setters for the
 * resolver that decides slot by slot. Every `make()` argument has a method of
 * the same name. The setters mutate and return $this, as Stories\Verb's
 * do; lists append (`attachments()`, `body()`) and maps merge in
 * `View::with()`'s manner (`attributes()`). The properties are
 * `private(set)`, so a presenter can read every slot and change none.
 */
final class FeedMedia
{
    use Conditionable;

    /** Where the resource lives: an href, or an image with its dimensions. */
    public private(set) FeedImage|string|null $url = null;

    /** A label that overrides the snapshot's, read fresh on every request. */
    public private(set) ?string $label = null;

    /** @var array<string, mixed> attributes for the rendered link */
    public private(set) array $attributes = [];

    /** Whether the link should open as a modal. */
    public private(set) bool $modal = false;

    public private(set) ?FeedImage $icon = null;

    public private(set) ?FeedImage $preview = null;

    public private(set) ?FeedImage $image = null;

    /** @var list<FeedResource> */
    public private(set) array $attachments = [];

    /**
     * Bodies resolved at read time, or closures that would build them.
     *
     * HELD AS GIVEN AND RESOLVED IN {@see $body}, never in a setter.
     * Every other slot here normalizes on the way in, and doing the same
     * to a closure would run it immediately — which is the whole thing a
     * closure is for not doing. A resolver runs for the url whether or not a
     * body is wanted, so the expensive half is handed over unbuilt and called
     * only on a read that draws one.
     *
     * @var list<string|FeedBody|array<mixed>|Closure>
     */
    private array $bodies = [];

    /**
     * The body resolved at read time, built now if it was handed over unbuilt.
     *
     * @var list<array<string, mixed>>
     */
    public array $body {
        get => $this->resolveBody();
    }

    /**
     * The body, built now, with each held body built on its own.
     *
     * WITH A RESCUE, A BODY THAT THROWS IS LEFT OUT and the rest are kept:
     * the closure is handed the exception and the forms around it still
     * arrive. The read path passes one, because an activity is never hidden
     * by the read path and a body is the costliest thing an app defers to it.
     * Without one the exception is thrown, as reading `$body` throws: outside
     * a feed read, a broken closure should be loud.
     *
     * @param  (Closure(Throwable): mixed)|null  $rescue
     * @return list<array<string, mixed>>
     */
    public function resolveBody(?Closure $rescue = null): array
    {
        $forms = [];

        foreach ($this->bodies as $body) {
            try {
                $forms[] = BodySlot::normalize($body);
            } catch (Throwable $e) {
                if ($rescue === null) {
                    throw $e;
                }

                $rescue($e);
            }
        }

        return array_merge(...$forms);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  iterable<FeedResource>  $attachments
     * @param  string|FeedBody|iterable<mixed>|Closure|null  $body
     */
    public function __construct(
        FeedImage|string|null $url = null,
        ?string $label = null,
        array $attributes = [],
        bool $modal = false,
        FeedImage|string|null $icon = null,
        FeedImage|string|null $preview = null,
        FeedImage|string|null $image = null,
        iterable $attachments = [],
        string|FeedBody|iterable|Closure|null $body = null,
    ) {
        $this->url($url)
            ->label($label)
            ->attributes($attributes)
            ->modal($modal)
            ->icon($icon)
            ->preview($preview)
            ->image($image)
            ->attachments($attachments)
            ->body($body);
    }

    /**
     * Start the media. Every argument is optional and has a method of the
     * same name, so `make()` with nothing is where the chain begins.
     *
     * @param  array<string, mixed>  $attributes
     * @param  iterable<FeedResource>  $attachments
     * @param  string|FeedBody|iterable<mixed>|Closure|null  $body
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
        string|FeedBody|iterable|Closure|null $body = null,
    ): self {
        return new self($url, $label, $attributes, $modal, $icon, $preview, $image, $attachments, $body);
    }

    /**
     * Where a tap goes: an href, or a FeedImage when the resource itself is
     * an image.
     */
    public function url(FeedImage|string|null $url): self
    {
        $this->url = $url;

        return $this;
    }

    /**
     * A label that replaces the snapshot's for this read.
     */
    public function label(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Attributes for the rendered link. An array MERGES — a repeated key
     * takes the later value — and a key with a value sets that one key, as
     * `View::with()` does.
     *
     * @param  array<string, mixed>|string  $key
     */
    public function attributes(array|string $key, mixed $value = null): self
    {
        if (is_string($key)) {
            $this->attributes[$key] = $value;

            return $this;
        }

        $this->attributes = array_merge($this->attributes, $key);

        return $this;
    }

    /**
     * Hint the renderer to open the link as a modal.
     */
    public function modal(bool $modal = true): self
    {
        $this->modal = $modal;

        return $this;
    }

    /**
     * Add attachments. Each call APPENDS, and order is kept as given: the
     * payload and the AS2 document both emit it in this sequence.
     *
     *     ->attachments($invoice, $receipt)
     *     ->attachments($document->files->map(…))
     *
     * @param  FeedResource|iterable<FeedResource>  ...$attachments
     */
    public function attachments(FeedResource|iterable ...$attachments): self
    {
        foreach ($attachments as $attachment) {
            array_push($this->attachments, ...self::resources($attachment instanceof FeedResource ? [$attachment] : $attachment));
        }

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

    /** The small representational image: an avatar, a logo. */
    public function icon(FeedImage|string|null $icon): self
    {
        $this->icon = $icon === null ? null : FeedImage::from($icon);

        return $this;
    }

    /** The derivative painted in a dense list: a thumbnail. */
    public function preview(FeedImage|string|null $preview): self
    {
        $this->preview = $preview === null ? null : FeedImage::from($preview);

        return $this;
    }

    /** A larger visual representation of a non-image object. */
    public function image(FeedImage|string|null $image): self
    {
        $this->image = $image === null ? null : FeedImage::from($image);

        return $this;
    }

    /**
     * Add a body resolved at read time. Each call APPENDS; a closure is held
     * unbuilt and called only when the body is read.
     *
     * @param  string|FeedBody|iterable<mixed>|Closure|null  ...$body
     */
    public function body(string|FeedBody|iterable|Closure|null ...$body): self
    {
        foreach ($body as $item) {
            if ($item === null || $item === '') {
                continue;
            }

            $this->bodies[] = is_iterable($item) && ! is_array($item)
                ? iterator_to_array($item, false)
                : $item;
        }

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
