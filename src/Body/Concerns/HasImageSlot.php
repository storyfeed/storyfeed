<?php

namespace Storyfeed\Body\Concerns;

use LogicException;
use Storyfeed\MediaSlot;

/**
 * Which of the entity's media slots is this body's picture. The body stores
 * the slot's name, never a URL; the renderer takes the resolved image from
 * the entity's media at read time.
 *
 * `image()` sets the slot outright. `withIcon()`, `withPreview()` and
 * `withImage()` refuse a second slot rather than replacing the first: a body
 * naming two slots is a body asking to be drawn twice, and last-wins would
 * turn that mistake into a silent layout.
 */
trait HasImageSlot
{
    protected ?MediaSlot $image = null;

    /**
     * Which of the entity's media slots is this body's picture. Sets it
     * outright; {@see withIcon()} and its siblings refuse a second slot.
     */
    public function image(?MediaSlot $image): static
    {
        $this->image = $image;

        return $this;
    }

    /** The picture is the entity's `icon` — which thing this is. */
    public function withIcon(): static
    {
        return $this->namingSlot(MediaSlot::Icon);
    }

    /** The picture is the entity's `preview` — a stand-in that previews the thing without depicting it. */
    public function withPreview(): static
    {
        return $this->namingSlot(MediaSlot::Preview);
    }

    /** The picture is the entity's `image` — what the thing looks like. */
    public function withImage(): static
    {
        return $this->namingSlot(MediaSlot::Image);
    }

    public function getImage(): ?MediaSlot
    {
        return $this->image;
    }

    private function namingSlot(MediaSlot $slot): static
    {
        if ($this->image !== null) {
            throw new LogicException(sprintf(
                'A %s names at most one image slot; this one already names `%s` and cannot also name `%s`.',
                class_basename(static::class),
                $this->image->value,
                $slot->value,
            ));
        }

        $this->image = $slot;

        return $this;
    }
}
