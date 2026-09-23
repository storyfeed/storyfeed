<?php

namespace Storyfeed;

use Closure;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Traits\Conditionable;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\Support\BodySlot;

/**
 * The cacheable feed representation of an entity: a label, snapshot data,
 * and a body.
 *
 * Returned by Feedable::toFeed() and persisted as a Snapshot. This class is
 * part of the versioned payload contract — see docs/payload.md.
 *
 *     return FeedEntity::make()
 *         ->label("Order #{$this->number}")
 *         ->data(['total' => $this->total])
 *         ->body(Excerpt::make()->text($this->note));
 *
 * ## Two ways in, one object out
 *
 * The chain and `make()` with named arguments reach exactly the same object:
 * every argument `make()` takes has a method of the same name. The chain is
 * what the guide teaches, because an entity is composed a piece at a time;
 * named arguments stay for the reader who wants it on one line.
 *
 * SETTERS CHANGE THIS OBJECT AND RETURN IT. Not a clone: a `describeFeed()`
 * hook calls `$this->feedEntity()->label(…)` and returns nothing, and a
 * clone would drop that work without a sound. Lists append (`body()`) and
 * maps merge in `View::with()`'s manner (`data()`), so a chain is
 * order-insensitive and can be built up across several places.
 *
 * ## `body` is the slot a body type goes in
 *
 * A body used to hide inside `data`, at a key the app chose, so finding one
 * meant walking the app's own map and a renderer had to be told how deep to
 * look. `body` is a slot core owns and STILL DOES NOT READ: it carries
 * whatever array a body produces, byte-identical, and `data` goes back to
 * being purely the app's, handed over unread.
 *
 * Owning the slot is not the same as knowing what is in it. Core never calls
 * a body type's `bodyType()` or `version()` to decide anything, which is what
 * keeps the vocabulary free to grow in a library core has never heard of.
 *
 * A body is turned into its array when the entity is USED, not when it is
 * added, so a body object configured after `->body($it)` still counts.
 *
 * ## No `component`
 *
 * A renderer hint named by a string predated bodies; it is a body now,
 * {@see Body\Component}, with a name and its props.
 */
final class FeedEntity
{
    use Conditionable;

    /** The entity's name as a feed shows it. */
    public private(set) ?string $label = null;

    /**
     * Authored text, never rendered here.
     */
    public private(set) ?string $content = null;

    /**
     * The encoding of `content`; null leaves AS2's text/html default implicit.
     */
    public private(set) ?string $mediaType = null;

    /**
     * The author's IRI, supplied explicitly rather than inferred from
     * whichever actor happens to perform an activity on this entity.
     */
    public private(set) ?string $attributedTo = null;

    /**
     * What the entity's tombstone keeps once it is deleted; see tombstone().
     *
     * @var (Closure(PendingTombstone): mixed)|null
     */
    public private(set) ?Closure $tombstone = null;

    /** @var array<string, mixed> */
    private array $values = [];

    /** @var list<string|FeedBody|array<mixed>> */
    private array $bodies = [];

    /**
     * The app's own map, with a nested `Arrayable` flattened.
     *
     * @var array<string, mixed>
     */
    public array $data {
        get => BodySlot::data($this->values);
    }

    /**
     * Every body, as the list of arrays a payload carries.
     *
     * @var list<array<string, mixed>>
     */
    public array $body {
        get => array_merge(...array_map(BodySlot::normalize(...), $this->bodies));
    }

    /**
     * @param  array<string, mixed>|Arrayable<string, mixed>  $data
     * @param  string|FeedBody|iterable<mixed>|null  $body  one body, several, or a line of text
     */
    public function __construct(
        ?string $label = null,
        array|Arrayable $data = [],
        ?string $content = null,
        ?string $mediaType = null,
        ?string $attributedTo = null,
        string|FeedBody|iterable|null $body = null,
    ) {
        $this->label($label)
            ->data($data)
            ->content($content)
            ->mediaType($mediaType)
            ->attributedTo($attributedTo)
            ->body($body);
    }

    /**
     * Start an entity. Every argument is optional and has a method of the
     * same name, so `make()` with nothing is where the chain begins.
     *
     * @param  array<string, mixed>|Arrayable<string, mixed>  $data
     * @param  string|FeedBody|iterable<mixed>|null  $body  one body, several, or a line of text
     */
    public static function make(
        ?string $label = null,
        array|Arrayable $data = [],
        ?string $content = null,
        ?string $mediaType = null,
        ?string $attributedTo = null,
        string|FeedBody|iterable|null $body = null,
    ): self {
        return new self($label, $data, $content, $mediaType, $attributedTo, $body);
    }

    /**
     * The entity's name as a feed shows it: "Order #1042".
     */
    public function label(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Add to the app's own map, which core stores and hands back unread.
     *
     * An array MERGES — a repeated key takes the later value — and a key with
     * a value sets that one key, as `View::with()` does:
     *
     *     ->data(['total' => $order->total])
     *     ->data('currency', 'EUR')
     *
     * @param  array<string, mixed>|Arrayable<string, mixed>|string  $key
     */
    public function data(array|Arrayable|string $key, mixed $value = null): self
    {
        if (is_string($key)) {
            $this->values[$key] = $value;

            return $this;
        }

        $this->values = array_merge($this->values, $key instanceof Arrayable ? $key->toArray() : $key);

        return $this;
    }

    /**
     * Add one body or several. Each call APPENDS, in the order written:
     *
     *     ->body($excerpt, $facts)
     *
     * A string is a line of text ({@see Body\Prose}); null adds nothing.
     *
     * @param  string|FeedBody|iterable<mixed>|null  ...$body
     */
    public function body(string|FeedBody|iterable|null ...$body): self
    {
        foreach ($body as $item) {
            if ($item === null || $item === '') {
                continue;
            }

            // A generator is read once; the entity is read more than once.
            $this->bodies[] = is_iterable($item) && ! is_array($item)
                ? iterator_to_array($item, false)
                : $item;
        }

        return $this;
    }

    /**
     * Authored text for the entity itself, never rendered here.
     */
    public function content(?string $content): self
    {
        $this->content = $content;

        return $this;
    }

    /**
     * The encoding of `content`, such as `text/markdown`. Null leaves AS2's
     * text/html default implicit.
     */
    public function mediaType(?string $mediaType): self
    {
        $this->mediaType = $mediaType;

        return $this;
    }

    /**
     * The author's IRI. AS2's term, and not `by()`, which names the actor
     * when recording an activity.
     */
    public function attributedTo(?string $attributedTo): self
    {
        $this->attributedTo = $attributedTo;

        return $this;
    }

    /**
     * What the tombstone keeps once the model is deleted. Read when it is:
     *
     *     ->tombstone(fn (PendingTombstone $tombstone) => $tombstone->keepLabel()->forgetActivities())
     *
     * Without it, the tombstone keeps nothing but the model's type and when
     * it went, and every story that named the model stays.
     *
     * @param  Closure(PendingTombstone): mixed  $configure
     */
    public function tombstone(Closure $configure): self
    {
        $this->tombstone = $configure;

        return $this;
    }
}
