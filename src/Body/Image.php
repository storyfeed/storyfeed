<?php

namespace Storyfeed\Body;

use Storyfeed\Body\Concerns\HasImageSlot;
use Storyfeed\DeferredMedia;
use Storyfeed\FeedBody;
use Storyfeed\FeedImage;
use Storyfeed\FeedLink;
use Storyfeed\MediaSlot;

/**
 * A picture: its own, stored in the body, or one of the entity's
 * `feedMedia()` pictures, resolved at read time.
 *
 *     // Its own picture: stored in the body, cacheable, no feedMedia() needed
 *     Image::make('https://cdn.example.com/kitchen/day-3.jpg')
 *         ->alt('Cabinets installed')->width(1200)->height(800)->caption('Day 3');
 *
 *     // One of this model's pictures, resolved from feedMedia() at read time
 *     Image::make($this->feedMediaIcon());
 *     Image::make($order->feedMediaPreview())->caption('Table 4');
 *     Image::make($this->getFeedMedia('sparkline'));
 *
 * Stored:
 *
 *     {"$body": "Storyfeed/Body/Image", "$v": 3, "src": "https://cdn…/day-3.jpg",
 *      "width": 1200, "height": 800, "alt": "Cabinets installed", "caption": "Day 3"}
 *     {"$body": "Storyfeed/Body/Image", "$v": 3, "image": "icon"}
 *
 * The trade-off, as {@see FeedLink}'s href makes it: a stored src
 * is cacheable and ages, by the app's choice; a `feedMedia()` picture always
 * shows the current one. `Image::make()` with neither shows the `preview` slot.
 */
class Image extends FeedBody
{
    use HasImageSlot {
        image as private slot;
    }

    protected ?string $caption = null;

    protected ?string $alt = null;

    protected ?int $width = null;

    protected ?int $height = null;

    /**
     * @param  string|FeedImage|DeferredMedia|MediaSlot|null  $image  a URL or {@see FeedImage} to store, or `$this->getFeedMedia()` and its shorthands; null is the `preview` slot
     */
    protected function __construct(string|FeedImage|DeferredMedia|MediaSlot|null $image = null, ?string $caption = null, ?string $alt = null, ?int $width = null, ?int $height = null)
    {
        $this->image($image)->caption($caption);

        $this->alt = $alt ?? $this->alt;
        $this->width($width ?? $this->width)->height($height ?? $this->height);
    }

    /**
     * The picture: a URL or {@see FeedImage} stored in the body, or one of
     * the entity's pictures from `$this->getFeedMedia()` and its shorthands.
     * A FeedImage's alt, width and height become this body's. Null is the
     * `preview` slot.
     */
    public function image(string|FeedImage|DeferredMedia|MediaSlot|null $image): static
    {
        $image = is_string($image) ? FeedImage::make($image) : $image;

        if ($image instanceof FeedImage) {
            $this->alt = $image->alt ?? $this->alt;
            $this->width = $image->width ?? $this->width;
            $this->height = $image->height ?? $this->height;
        }

        return $this->slot($image);
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

    /**
     * 2 since 2026-10-09: a field is written only when it is set, and the
     * `preview` slot only when named otherwise. 3 since 2026-10-09: the body
     * may store its own picture as `src` (#88), and names its slot always.
     */
    public static function version(): int
    {
        return 3;
    }

    public static function upgrade(array $payload, int $from): array
    {
        $src = $from >= 3 && is_string($payload['src'] ?? null) && $payload['src'] !== '' ? $payload['src'] : null;

        // Until v3 a missing slot meant `preview`; a slot the vocabulary
        // never issued, such as `url`, draws no picture.
        $image = $src === null ? DeferredMedia::tryFromPayload($payload['image'] ?? ($from < 3 ? 'preview' : null))?->value : null;

        return [
            'src' => $src,
            'mediaType' => $src !== null && is_string($payload['mediaType'] ?? null) ? $payload['mediaType'] : null,
            'width' => is_int($payload['width'] ?? null) && $payload['width'] > 0 ? $payload['width'] : null,
            'height' => is_int($payload['height'] ?? null) && $payload['height'] > 0 ? $payload['height'] : null,
            'alt' => is_string($payload['alt'] ?? null) ? $payload['alt'] : null,
            'caption' => is_string($payload['caption'] ?? null) ? $payload['caption'] : null,
            'image' => $image,
        ];
    }

    protected function body(): array
    {
        $own = $this->image instanceof FeedImage ? $this->image : null;

        return [
            'src' => $own?->src,
            'mediaType' => $own?->mediaType,
            'width' => $this->width,
            'height' => $this->height,
            'alt' => $this->alt,
            'caption' => $this->caption,
            'image' => $own === null ? ($this->image ?? DeferredMedia::slot(MediaSlot::Preview))->value : null,
        ];
    }

    protected static function defaults(): array
    {
        return ['src' => null, 'mediaType' => null, 'width' => null, 'height' => null, 'alt' => null, 'caption' => null, 'image' => null];
    }
}
