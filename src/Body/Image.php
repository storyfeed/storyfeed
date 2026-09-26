<?php

namespace Storyfeed\Body;

use Illuminate\Support\Traits\Conditionable;
use LogicException;
use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\MediaSlot;

/** A picture resolved from the entity's named media slot at read time. No URL is stored. */
class Image implements FeedBody
{
    use Conditionable;
    use HasPayload;

    private ?string $caption = null;

    private ?string $alt = null;

    private ?int $width = null;

    private ?int $height = null;

    private ?MediaSlot $image = null;

    final protected function __construct() {}

    public static function make(?string $caption = null, ?string $alt = null, ?int $width = null, ?int $height = null): static
    {
        return (new static)->caption($caption)->alt($alt)->width($width)->height($height);
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

    public function withIcon(): static
    {
        return $this->naming(MediaSlot::Icon);
    }

    public function withPreview(): static
    {
        return $this->naming(MediaSlot::Preview);
    }

    public function withImage(): static
    {
        return $this->naming(MediaSlot::Image);
    }

    private function naming(MediaSlot $slot): static
    {
        if ($this->image !== null) {
            throw new LogicException('An Image names at most one image slot.');
        }

        $this->image = $slot;

        return $this;
    }

    public static function bodyType(): string
    {
        return 'Storyfeed/Body/Image';
    }

    public static function version(): int
    {
        return 1;
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

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            self::KEY => self::bodyType(),
            self::VERSION => self::version(),
            'caption' => $this->caption,
            'alt' => $this->alt,
            'width' => $this->width,
            'height' => $this->height,
            'image' => ($this->image ?? MediaSlot::Preview)->value,
        ];
    }
}
