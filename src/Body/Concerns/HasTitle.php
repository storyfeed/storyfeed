<?php

namespace Storyfeed\Body\Concerns;

/**
 * A line above the body, when the headline does not already say it.
 *
 * Spelled `title`, the word RSS, Atom and JSON Feed use, and the one every
 * body type with a heading line shares.
 */
trait HasTitle
{
    protected ?string $title = null;

    /** A line above the body, when the headline does not already say it. */
    public function title(?string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function getTitle(): ?string
    {
        return $this->title;
    }
}
