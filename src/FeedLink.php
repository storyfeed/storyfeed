<?php

namespace Storyfeed;

/**
 * A link to an entity, generated fresh at read time from cached snapshot
 * data so links never go stale while labels stay fast.
 *
 * Returned by Feedable::toFeedLink(). Part of the versioned payload contract.
 */
final class FeedLink
{
    public function __construct(
        public readonly ?string $url = null,
        public readonly ?string $label = null,
        public readonly array $attributes = [],
        public readonly bool $modal = false,
    ) {}

    public static function make(
        ?string $url = null,
        ?string $label = null,
        array $attributes = [],
        bool $modal = false,
    ): self {
        return new self($url, $label, $attributes, $modal);
    }

    /**
     * A link that should open as a modal.
     */
    public static function modal(?string $url = null, ?string $label = null, array $attributes = []): self
    {
        return new self($url, $label, $attributes, modal: true);
    }
}
