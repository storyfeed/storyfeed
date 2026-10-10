<?php

namespace Storyfeed\Concerns;

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
        ];
    }
}
