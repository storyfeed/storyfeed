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
    public readonly array $data;

    public function __construct(
        public readonly ?string $label = null,
        array|Arrayable $data = [],
        public readonly ?string $component = null,
    ) {
        $this->data = $data instanceof Arrayable ? $data->toArray() : $data;
    }

    public static function make(
        ?string $label = null,
        array|Arrayable $data = [],
        ?string $component = null,
    ): self {
        return new self($label, $data, $component);
    }
}
