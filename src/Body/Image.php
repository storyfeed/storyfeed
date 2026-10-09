<?php

namespace Storyfeed\Body;

use Storyfeed\Body\Concerns\HasImageSlot;
use Storyfeed\FeedBody;
use Storyfeed\MediaSlot;

/** A picture resolved from the entity's named media slot at read time. No URL is stored. */
class Image extends FeedBody
{
    use HasImageSlot;

    protected ?string $caption = null;

    protected ?string $alt = null;

    protected ?int $width = null;

    protected ?int $height = null;

    protected function __construct(?string $caption = null, ?string $alt = null, ?int $width = null, ?int $height = null, ?MediaSlot $image = null)
    {
        $this->caption($caption)->alt($alt)->width($width)->height($height)->image($image);
    }

    public function caption(?string $caption): static
    {
        $this->caption = $caption;

        return $this;
    }

    public function alt(?string $alt): static
    {
        $this->alt = $alt;

        return $this;
    }

    public function width(?int $width): static
    {
        $this->width = $width !== null && $width > 0 ? $width : null;

        return $this;
    }

    public function height(?int $height): static
    {
        $this->height = $height !== null && $height > 0 ? $height : null;

        return $this;
    }

    public static function bodyType(): string
    {
        return 'Storyfeed/Body/Image';
    }

    /** 2 since 2026-10-09: a field is written only when it is set, and the `preview` slot only when named otherwise. */
    public static function version(): int
    {
        return 2;
    }

    public static function upgrade(array $payload, int $from): array
    {
        $image = $payload['image'] ?? 'preview';

        return [
            'caption' => is_string($payload['caption'] ?? null) ? $payload['caption'] : null,
            'alt' => is_string($payload['alt'] ?? null) ? $payload['alt'] : null,
            'width' => is_int($payload['width'] ?? null) && $payload['width'] > 0 ? $payload['width'] : null,
            'height' => is_int($payload['height'] ?? null) && $payload['height'] > 0 ? $payload['height'] : null,
            'image' => is_string($image) ? MediaSlot::tryFrom($image)?->value : null,
        ];
    }

    protected function body(): array
    {
        return [
            'caption' => $this->caption,
            'alt' => $this->alt,
            'width' => $this->width,
            'height' => $this->height,
            'image' => ($this->image ?? MediaSlot::Preview)->value,
        ];
    }

    protected static function defaults(): array
    {
        return ['caption' => null, 'alt' => null, 'width' => null, 'height' => null, 'image' => MediaSlot::Preview->value];
    }
}
