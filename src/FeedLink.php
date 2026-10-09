<?php

namespace Storyfeed;

use Illuminate\Support\Traits\Conditionable;
use Storyfeed\Concerns\HasPayload;
use Storyfeed\Exceptions\IncompleteFeedValue;

/**
 * A place you go: where a tap leads, and how the renderer should open it.
 *
 *     FeedLink::to(route('photos.show', $photo))->modal()     // an entity's link
 *     FeedLink::make('The recall notice', $notice->url)       // a link in a body
 *     FeedLink::toEntity($dish->name)                         // this entity's own link
 *
 * The one link type, everywhere a payload carries one: an entity's link
 * (`FeedMedia::link()`) and the links inside bodies share the shape
 * `{label, href, modal, attributes}`. `modal` and `attributes` are
 * suggestions to a renderer, as they always were on the entity; they now
 * live on the link, where they belong (ruled 2026-09-26, #79).
 *
 * ## The constructors
 *
 * `to($href)` is href first, after Laravel's `URL::to()` and
 * `redirect()->to()`, for the links that need no label: an entity's own, a
 * call to action's. `make($label, $href)` is label first, for body links,
 * where the label is the point. `toEntity($label)` is the redirector's
 * `toRoute()` move: not a URL, but a named place the reader resolves, here
 * the entity the body belongs to.
 *
 * ## Nothing is inferred from a missing href
 *
 * `toEntity()` stores no location, and a renderer resolves it against the
 * entity's own `link` at read time, every time: a URL copied into a snapshot
 * ages, as {@see FeedImage}'s src would. In the payload that is `href: null`,
 * the shape such a link always had, so rows written before this class grew
 * keep their meaning. A `make()` or `to()` link without an href is a mistake
 * and throws when it is written, naming `href()`: a reader of
 * `FeedLink::make($photo->title)` should not have to guess where it goes.
 *
 * ## Not FeedResource, which is also an AS2 Link
 *
 * {@see FeedResource} is a FILE you fetch: its href is required and it
 * carries a `mediaType`, because something has to be decided before it is
 * opened. A link is a PLACE you go, and navigating is not fetching.
 *
 * ## The label is the title, not a verb
 *
 *     FeedLink::make('Open the conversation', $url)      // ← the defect
 *
 * A label that says what a reader should DO is a call to action wearing a
 * title's clothes, and it reads as one the moment two rows carry it. The
 * label names the thing. A call to action is a body of its own, whose text
 * is a verb by design. The label is optional on the class: an entity's link
 * takes its name from the entity. A body that draws the label requires it
 * and throws, naming `label()`.
 *
 * Core owns this payload slot, so no `$body` discriminator or storage version
 * travels with it.
 */
final class FeedLink
{
    use Conditionable;
    use HasPayload;

    /** The text a reader sees — the thing's name, never an instruction. */
    public private(set) ?string $label = null;

    /** Where a tap goes. Null on a {@see toEntity()} link, which the renderer resolves. */
    public private(set) ?string $href = null;

    /** Whether the renderer should open the link as a modal. */
    public private(set) bool $modal = false;

    /** @var array<string, mixed> attributes for the rendered link */
    public private(set) array $attributes = [];

    /** Whether this is the entity's own link, resolved by the renderer. */
    public private(set) bool $entity = false;

    public function __construct(?string $label = null, ?string $href = null)
    {
        $this->label($label)->href($href);
    }

    /**
     * Start a link, label first, for a body.
     *
     * @param  string|null  $label  the text a reader sees — the thing's name, never an instruction
     * @param  string|null  $href  where a tap goes; required by the time the link is written
     */
    public static function make(?string $label = null, ?string $href = null): self
    {
        return new self($label, $href);
    }

    /** A link to `$href`, after Laravel's `URL::to()` and `redirect()->to()`. */
    public static function to(string $href): self
    {
        return new self(href: $href);
    }

    /**
     * The entity's own link, resolved by the renderer against `entity.link`
     * each time the feed is read. Its `modal()` and `attributes()` add to the
     * entity's.
     */
    public static function toEntity(?string $label = null): self
    {
        $link = new self($label);
        $link->entity = true;

        return $link;
    }

    /** The text a reader sees — the thing's name, never an instruction. */
    public function label(?string $label): self
    {
        $this->label = $label === '' ? null : $label;

        return $this;
    }

    /** Where a tap goes. */
    public function href(?string $href): self
    {
        $this->href = $href === '' ? null : $href;

        return $this;
    }

    /** Suggest that the renderer open the link as a modal. */
    public function modal(bool $modal = true): self
    {
        $this->modal = $modal;

        return $this;
    }

    /**
     * Attributes for the rendered link, such as `['target' => '_blank']`. An
     * array MERGES — a repeated key takes the later value — and a key with a
     * value sets that one key, as `View::with()` does.
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
     * Rehydrate from a stored value, or null if it is not one of these.
     *
     * A PLAIN STRING IS NOT A LINK AND MUST NOT BECOME ONE. A field that
     * accepts `string|FeedLink` uses the two to mean different things: text
     * that leads nowhere, and text that leads somewhere. Converting a string
     * here would make every unlinked title clickable the moment its field
     * widened. The caller keeps the union; this method only ever answers
     * about a link.
     *
     * A stored `href: null` is the entity's own link, as it was before
     * `toEntity()` named it. Anything malformed is null: it renders as
     * nothing, never as a broken row. Same rule as an unknown body.
     */
    public static function from(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_array($value) || ! array_key_exists('href', $value)) {
            return null;
        }

        $label = $value['label'] ?? null;
        $href = $value['href'] ?? null;

        if (($label !== null && ! is_string($label)) || ($href !== null && ! is_string($href))) {
            return null;
        }

        $link = $href === null || $href === '' ? self::toEntity($label) : self::make($label, $href);

        $attributes = $value['attributes'] ?? [];

        return $link
            ->modal(($value['modal'] ?? false) === true)
            ->attributes(is_array($attributes) ? array_filter($attributes, is_string(...), ARRAY_FILTER_USE_KEY) : []);
    }

    /**
     * @return array{label: string|null, href: string|null, modal: bool, attributes: array<string, mixed>}
     */
    public function toPayload(): array
    {
        return [
            'label' => $this->label,
            'href' => $this->entity ? null : ($this->href ?? throw IncompleteFeedValue::missing(self::class, 'href')),
            'modal' => $this->modal,
            'attributes' => $this->attributes,
        ];
    }
}
