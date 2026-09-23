<?php

namespace Storyfeed;

use Illuminate\Contracts\Support\Arrayable;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\Support\BodySlot;

/**
 * The cacheable feed representation of an entity: a label, snapshot data,
 * a body, and an optional body-component hint for renderers.
 *
 * Returned by Feedable::toFeed() and persisted as a Snapshot. This class is
 * part of the versioned payload contract — see docs/payload.md.
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
 * a body type's `name()` or `version()` to decide anything, which is what keeps
 * the vocabulary free to grow in a library core has never heard of.
 */
final class FeedEntity
{
    /** @var array<string, mixed> */
    public readonly array $data;

    /** @var list<array<string, mixed>> */
    public readonly array $body;

    /**
     * content is authored text, never rendered here. mediaType describes its
     * encoding; null leaves AS2's text/html default implicit. attributedTo
     * is the author's IRI, supplied explicitly rather than inferred from
     * whichever actor happens to perform an activity on this entity.
     *
     * @param  array<string, mixed>|Arrayable<string, mixed>  $data
     * @param  string|FeedBody|iterable<mixed>|null  $body  one body, several, or a line of text
     */
    public function __construct(
        public readonly ?string $label = null,
        array|Arrayable $data = [],
        public readonly ?string $component = null,
        public readonly ?string $content = null,
        public readonly ?string $mediaType = null,
        public readonly ?string $attributedTo = null,
        string|FeedBody|iterable|null $body = null,
    ) {
        $this->data = BodySlot::data($data);
        $this->body = BodySlot::normalize($body);
    }

    /**
     * @param  array<string, mixed>|Arrayable<string, mixed>  $data
     * @param  string|FeedBody|iterable<mixed>|null  $body
     */
    public static function make(
        ?string $label = null,
        array|Arrayable $data = [],
        ?string $component = null,
        ?string $content = null,
        ?string $mediaType = null,
        ?string $attributedTo = null,
        string|FeedBody|iterable|null $body = null,
    ): self {
        return new self($label, $data, $component, $content, $mediaType, $attributedTo, $body);
    }
}
