<?php

namespace Storyfeed;

use Illuminate\Support\Traits\Conditionable;
use Storyfeed\Concerns\HasPayload;
use Storyfeed\Exceptions\IncompleteFeedValue;

/**
 * One entry in FeedMedia::attachments — a typed resource link, a PDF or an
 * archive. The href is resolved at read time, just like FeedImage::src. Field
 * names follow AS2 Link; the attachment owns its object type, separate from its
 * Link. Core owns this payload slot, so no `$body` discriminator or storage
 * version travels with it.
 *
 *     FeedResource::make()->href($url)->mediaType('application/pdf')->name('Invoice.pdf')
 */
final class FeedResource
{
    use Conditionable;
    use HasPayload;

    /**
     * Where the file is, resolved at read time. Required: reading it before
     * `href()` is called throws, naming the method.
     */
    public private(set) string $href {
        get => $this->href ?? throw IncompleteFeedValue::missing(self::class, 'href');
    }

    public private(set) ?string $mediaType = null;

    public private(set) ?string $name = null;

    public private(set) string $type = 'Document';

    public function __construct(?string $href = null, ?string $mediaType = null, ?string $name = null, string $type = 'Document')
    {
        if ($href !== null) {
            $this->href($href);
        }

        $this->mediaType($mediaType)->name($name)->type($type);
    }

    /**
     * Start a resource. Every argument is optional and has a method of the
     * same name; `href` must be set before the resource is used.
     */
    public static function make(?string $href = null, ?string $mediaType = null, ?string $name = null, string $type = 'Document'): self
    {
        return new self($href, $mediaType, $name, $type);
    }

    /** Where the file is. */
    public function href(string $href): self
    {
        $this->href = $href;

        return $this;
    }

    /** The file's MIME type, such as `application/pdf`. */
    public function mediaType(?string $mediaType): self
    {
        $this->mediaType = $mediaType;

        return $this;
    }

    /** The file's name as a reader sees it. */
    public function name(?string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /** The AS2 object type: `Document` unless the file is something more specific. */
    public function type(string $type): self
    {
        $this->type = $type;

        return $this;
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
