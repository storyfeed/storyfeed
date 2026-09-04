<?php

namespace Storyfeed;

/**
 * What a resolver knows about an entity at read time that its snapshot
 * cannot cache: a fresh url, an optional label override, link attributes
 * and a modal hint. Returned by HasFeedMedia::feedMedia().
 *
 * Subsumes FeedLink, which stopped being "a link" once it grew a label and
 * a modal flag. The url is one slot among several; media slots are planned
 * to join it, so the name says media rather than link from the start. A
 * FeedLink from the older contract converts losslessly via fromLink(), so
 * the read path only ever handles one shape. Part of the versioned payload
 * contract.
 */
final class FeedMedia
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public readonly ?string $url = null,
        public readonly ?string $label = null,
        public readonly array $attributes = [],
        public readonly bool $modal = false,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function make(
        ?string $url = null,
        ?string $label = null,
        array $attributes = [],
        bool $modal = false,
    ): self {
        return new self($url, $label, $attributes, $modal);
    }

    /**
     * Media whose link should open as a modal.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function modal(?string $url = null, ?string $label = null, array $attributes = []): self
    {
        return new self($url, $label, $attributes, modal: true);
    }

    /**
     * Lift a toFeedLink() result into the current shape, so the presenter
     * and serializer never branch on which contract answered.
     */
    public static function fromLink(FeedLink $link): self
    {
        return new self($link->url, $link->label, $link->attributes, $link->modal);
    }
}
