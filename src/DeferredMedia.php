<?php

namespace Storyfeed;

use InvalidArgumentException;

/**
 * One of a model's `feedMedia()` pictures, delivered when the feed is
 * rendered. What `getFeedMedia()` and its shorthands return:
 *
 *     Image::make($this->feedMediaIcon())                  // built-in: icon, preview, image
 *     Image::make($this->getFeedMedia('sparkline'))        // a custom slot, set with FeedMedia::slot()
 *     MediaObject::make(subject: $this->title)->image($this->getFeedMedia('preview'))
 *
 * A body stores the slot's name (`"image": "icon"`, or
 * `"image": "slots.sparkline"` for a custom slot) and the renderer takes the
 * picture from the entity's `media` at read time, so a changed thumbnail
 * redraws every row that shows it. It is a typed value rather than a string
 * so that `FeedImage::make()->src($this->feedMediaIcon())` is a type error,
 * not a row with the word `icon` stored as a URL.
 *
 * The slot is not checked against the resolver: a body showing a slot the
 * resolver leaves empty draws no picture.
 */
final readonly class DeferredMedia
{
    /** How a body stores it: `icon`, `preview`, `image`, or `slots.<name>`. */
    public string $value;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * A built-in slot by its name or case, or a custom slot by the name given
     * to {@see FeedMedia::slot()}.
     *
     * @throws InvalidArgumentException when the name is not a slot name
     */
    public static function slot(string|MediaSlot $slot): self
    {
        if ($slot instanceof MediaSlot) {
            return new self($slot->value);
        }

        if (MediaSlot::tryFrom($slot) !== null) {
            return new self($slot);
        }

        return new self('slots.'.self::name($slot));
    }

    /**
     * The value a stored body names, or null when it names nothing this
     * vocabulary issues — `url`, which is never a picture, or a malformed name.
     */
    public static function tryFromPayload(mixed $value): ?self
    {
        if (! is_string($value)) {
            return null;
        }

        if (MediaSlot::tryFrom($value) !== null) {
            return new self($value);
        }

        return str_starts_with($value, 'slots.') && self::valid(substr($value, 6)) ? new self($value) : null;
    }

    /**
     * A custom slot's name: letters, digits, `_` and `-`, starting with a
     * letter. No dot, because the stored value is a path.
     *
     * @throws InvalidArgumentException when it is not one
     */
    public static function name(string $name): string
    {
        if (! self::valid($name)) {
            throw new InvalidArgumentException(sprintf(
                'A feed media slot name is letters, digits, `_` and `-`, starting with a letter, such as `sparkline`; `%s` given.',
                $name,
            ));
        }

        return $name;
    }

    private static function valid(string $name): bool
    {
        return preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $name) === 1;
    }
}
