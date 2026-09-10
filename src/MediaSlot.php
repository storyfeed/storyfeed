<?php

namespace Storyfeed;

/**
 * One of FeedMedia's image slots, named so that something stored can refer
 * to it without holding the image.
 *
 *     public function toFeed(): FeedEntity
 *     {
 *         return FeedEntity::make($this->name, data: MediaObject::make(
 *             subject: $this->name,
 *             content: $this->summary,
 *             image: $this->feedMediaIcon(),     // MediaSlot::Icon
 *         ));
 *     }
 *
 * ## Why a slot name is what gets stored
 *
 * {@see FeedImage} is what `feedMedia()` RETURNS and not what `toFeed()`
 * stores: a src ages, so the snapshot keeps the intrinsic facts and the
 * resolver mints the location at read time. Anything that IS stored — a
 * detail in `data`, most of all — therefore cannot hold a `FeedImage`
 * without storing exactly the URL that rule forbids. What it can hold is a
 * reference: "my picture is my `icon`". At read time the renderer takes
 * `entity.media.icon` — already minted, already beside the detail in the
 * payload — and draws it with a live src, a live aspect box and live alt.
 * Change a thumbnail conversion and every historical row draws the new one,
 * because none of them stored a size.
 *
 * ## The cases are AS2's words, and the slot IS the meaning
 *
 * The three cases are the three image slots of {@see FeedMedia}, spelled
 * as the payload spells them (`entity.media.icon`, `.preview`, `.image`),
 * which is why the enum lives here rather than in the library that first
 * needed it: the slots are FeedMedia's, so their names are core's. Core
 * learns no detail's name or shape from this — the enum names core's own
 * slots, and that is all it does.
 *
 * The slot answers what the media IS to the entity. `icon` is which thing
 * this is: small and REPRESENTATIONAL, and a representation may be
 * borrowed from what the thing is about — a discussion about a dish is
 * represented by the dish. `image` is what the thing looks like: a larger
 * visual representation OF the entity itself. `preview` is a stand-in: it
 * does not depict the entity, it previews it — a link card's og:image, a
 * video's poster frame, the thumbnail for the full photograph at `url`.
 * Media should be OF the entity unless the slot is `icon`; and `preview`
 * is the slot reached for when the other two do not obviously fit, which
 * is how a photograph of food ended up under a reply about a dish.
 *
 * `url` is deliberately not a case. It is where the resource itself lives
 * and where a tap goes, not a picture of the resource; a stored block that
 * wants a photo object's picture names its `preview`, and the tap still
 * goes to `url`.
 *
 * ## A reference, not a resolution
 *
 * Naming a slot does not fetch it, and it does not check that the entity's
 * resolver will ever fill it. A stored block naming an empty slot draws
 * nothing, silently — the same rule as an unknown detail. Whether a model's
 * resolver actually sets the slot its details name is statically knowable
 * and is a doctor check's business, not this enum's.
 *
 * The forwarding helpers on {@see Concerns\InteractsWithFeed} —
 * `feedMediaIcon()`, `feedMediaPreview()`, `feedMediaImage()` — return these
 * cases and exist only so the call site reads as the sentence it is: "the
 * image is my feedMedia's icon".
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
