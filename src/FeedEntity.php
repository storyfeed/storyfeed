<?php

namespace Storyfeed;

use Illuminate\Contracts\Support\Arrayable;

/**
 * The cacheable feed representation of an entity: a label, snapshot data,
 * and an optional body-component hint for renderers.
 *
 * Returned by Feedable::toFeed() and persisted as a Snapshot. This class is
 * part of the versioned payload contract — see docs/payload.md.
 */
final class FeedEntity
{
    /** @var array<string, mixed> */
    public readonly array $data;

    /**
     * content is authored text, never rendered here. mediaType describes its
     * encoding; null leaves AS2's text/html default implicit. attributedTo
     * is the author's IRI, supplied explicitly rather than inferred from
     * whichever actor happens to perform an activity on this entity.
     *
     * @param  array<string, mixed>|Arrayable<string, mixed>  $data
     */
    public function __construct(
        public readonly ?string $label = null,
        array|Arrayable $data = [],
        public readonly ?string $component = null,
        public readonly ?string $content = null,
        public readonly ?string $mediaType = null,
        public readonly ?string $attributedTo = null,
    ) {
        $this->data = $data instanceof Arrayable ? $data->toArray() : $data;
    }

    /**
     * @param  array<string, mixed>|Arrayable<string, mixed>  $data
     */
    public static function make(
        ?string $label = null,
        array|Arrayable $data = [],
        ?string $component = null,
        ?string $content = null,
        ?string $mediaType = null,
        ?string $attributedTo = null,
    ): self {
        return new self($label, $data, $component, $content, $mediaType, $attributedTo);
    }
}
