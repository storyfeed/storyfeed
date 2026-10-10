<?php

namespace Storyfeed\Body\Concerns;

use Storyfeed\DeferredMedia;
use Storyfeed\FeedImage;
use Storyfeed\MediaSlot;

/**
 * This body's picture: its own, or one of the entity's `feedMedia()` pictures.
 *
 *     ->image(FeedImage::make()->src($url)->alt('Cabinets installed'))   // stored in the body
 *     ->image($this->feedMediaIcon())                                    // resolved at read time
 *
 * The trade-off, stated plainly: a stored picture is cacheable and needs no
 * `feedMedia()`, and its src ages (disks move, signed links expire). A
 * `feedMedia()` picture is the body naming a slot, so it always shows the
 * current picture and stores no URL.
 */
trait HasImageSlot
{
    protected FeedImage|DeferredMedia|null $image = null;

    /**
     * This body's picture: a {@see FeedImage} it stores, or one of the
     * entity's pictures from `$this->getFeedMedia()` or its shorthands. A
     * {@see MediaSlot} case names a built-in slot. Sets it outright.
     */
    public function image(FeedImage|DeferredMedia|MediaSlot|null $image): static
    {
        $this->image = $image instanceof MediaSlot ? DeferredMedia::slot($image) : $image;

        return $this;
    }

    public function getImage(): FeedImage|DeferredMedia|null
    {
        return $this->image;
    }
}
