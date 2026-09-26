<?php

namespace Storyfeed;

/**
 * Internal vocabulary for the three picture slots in FeedMedia.
 * Bodies name these slots through withIcon(), withPreview(), or withImage().
 * The entity URL is a link destination, never a picture slot.
 */
enum MediaSlot: string
{
    /** Small and representational, ~32×32, 1:1 — which thing this is, possibly by association. */
    case Icon = 'icon';

    /** A stand-in that previews the resource without depicting it — a link card's og:image, a poster frame. */
    case Preview = 'preview';

    /** A larger visual representation of a NON-image object — what the thing looks like. */
    case Image = 'image';
}
