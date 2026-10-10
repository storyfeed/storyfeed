<?php

namespace Storyfeed;

/**
 * The three built-in picture slots in FeedMedia. A body shows one through
 * the Feedable helpers — `$this->feedMediaIcon()`, `feedMediaPreview()`,
 * `feedMediaImage()`, or `getFeedMedia()` for any slot — which return a
 * {@see DeferredMedia}; a case is accepted wherever that is.
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
