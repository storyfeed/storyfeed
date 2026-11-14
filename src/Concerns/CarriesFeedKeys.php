<?php

namespace Storyfeed\Concerns;

use Storyfeed\Payload\FeedPage;

/**
 * The two feed-level values a page of nodes travels with, as extra top-level
 * keys after Laravel's own pagination keys: the place an API resource's
 * `additional()` puts them (`{data, links, meta, ...additional}`).
 *
 * @internal
 */
trait CarriesFeedKeys
{
    protected ?string $syncToken = null;

    /** @var array<string, array{link: array<string, mixed>|null, media: array<string, mixed>}> */
    protected array $mediaMap = [];

    /** @var array<string, array<string, mixed>> */
    protected array $entityMap = [];

    /**
     * The page's entity map (#246): each distinct entity, whole, keyed by
     * the `entity_ref` the page's entities carry.
     *
     * @return array<string, array<string, mixed>>
     */
    public function entities(): array
    {
        return $this->entityMap;
    }

    /**
     * The page's media map (#242): each distinct `{link, media}` pair, keyed
     * by the `media_ref` the page's entities carry.
     *
     * @return array<string, array{link: array<string, mixed>|null, media: array<string, mixed>}>
     */
    public function media(): array
    {
        return $this->mediaMap;
    }

    /**
     * Opaque, cursor-grained: store it; when a later page's token differs,
     * settled history was rewritten — drop ALL accumulated nodes and
     * refetch. Null until the first rewrite ever, and on a source's pages.
     */
    public function syncToken(): ?string
    {
        return $this->syncToken;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            ...parent::toArray(),
            'payload_version' => 1,
            'sync_token' => $this->syncToken,
            // Additive (2026-11-13, #242). Filled only when inline_media is
            // off: with the inline copies on, the map would duplicate them.
            // Empty otherwise, which JSON writes as `[]`; the key stays.
            // With entities off, the entity map carries each entity's link
            // and media whole, so the media map would only repeat them.
            'media' => FeedPage::inlineEntities() && ! FeedPage::inlineMedia() ? $this->mediaMap : [],
            // Additive (2026-11-14, #246). Filled only when inline_entities
            // is off, for the same reason; `[]` otherwise, the key stays.
            'entities' => FeedPage::inlineEntities() ? [] : $this->entityMap,
        ];
    }
}
