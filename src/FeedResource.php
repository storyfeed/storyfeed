<?php

namespace Storyfeed;

use Storyfeed\Concerns\HasPayload;

/**
 * One entry in FeedMedia::attachments — a typed resource link, a PDF or an
 * archive. The href is minted at read time, just like FeedImage::src. Field
 * names follow AS2 Link; the attachment owns its object type, separate from
 * its Link.
 * Core owns this payload slot, so no detail discriminator or storage version travels with it.
 */
final readonly class FeedResource
{
    use HasPayload;

    public function __construct(
        public string $href,
        public ?string $mediaType = null,
        public ?string $name = null,
        public string $type = 'Document',
    ) {}

    public static function make(string $href, ?string $mediaType = null, ?string $name = null, string $type = 'Document'): self
    {
        return new self($href, $mediaType, $name, $type);
    }

    /** @return array{href: string, mediaType: string|null, name: string|null, type: string} */
    public function toPayload(): array
    {
        return [
            'type' => $this->type,
            'href' => $this->href,
            'mediaType' => $this->mediaType,
            'name' => $this->name,
        ];
    }
}
