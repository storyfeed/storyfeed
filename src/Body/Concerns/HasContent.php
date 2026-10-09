<?php

namespace Storyfeed\Body\Concerns;

use Stringable;

/**
 * The body's text. A scalar or a `Stringable` becomes its string; anything
 * else is no text at all, and null clears it.
 */
trait HasContent
{
    protected ?string $content = null;

    /** The text itself. */
    public function content(mixed $content): static
    {
        $this->content = match (true) {
            $content === null => null,
            is_string($content) => $content,
            is_scalar($content) => (string) $content,
            $content instanceof Stringable, is_object($content) && method_exists($content, '__toString') => (string) $content,
            default => '',
        };

        return $this;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }
}
