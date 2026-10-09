<?php

namespace Storyfeed\Body\Concerns;

use Storyfeed\FeedLink;

/**
 * Small print under the content: a credit, an approval, never a second
 * paragraph. A {@see FeedLink} lets it lead somewhere; a plain string never
 * becomes a link.
 */
trait HasFootnote
{
    protected string|FeedLink|null $footnote = null;

    /** Small print under the content — a credit, an approval; never a second paragraph. */
    public function footnote(string|FeedLink|null $footnote): static
    {
        $this->footnote = $footnote;

        return $this;
    }

    public function getFootnote(): string|FeedLink|null
    {
        return $this->footnote;
    }
}
