<?php

namespace Storyfeed;

use Storyfeed\Concerns\HasPayload;

/**
 * A piece of text that leads somewhere: a label, and where a tap on it goes.
 *
 *     FeedLink::make('N201 Saffron Butter Rice')              // → my entity
 *     FeedLink::make('The recall notice', 'https://…')        // → there
 *
 * It exists so that a stored block can say "my title is a way in" without a
 * consumer reaching into a renderer's markup to add one. A consumer who has
 * to open a Blade file to make a title clickable ships a card containing the
 * words *Open the conversation*, and three of those in one viewport outweigh
 * the words they are a way into. That happened; this class is the answer.
 *
 * ## A null href means "my entity", and that is the case worth defaulting
 *
 * An entity's url is minted at read time by {@see Support\LinkResolver} for
 * the same reason {@see FeedImage}'s src is: a URL copied into a snapshot
 * ages. Disks move, signed links expire, routes get renamed, slugs change.
 * A stored href is a second copy of something the app already resolves live,
 * and every historical row would keep the route you had on the day you wrote
 * it.
 *
 * So the common case — a title that leads to the thing the row is about —
 * stores no location at all. `href` is null and the renderer resolves it
 * against the entity the block belongs to, exactly as a stored `image:
 * "icon"` resolves against `entity.media.icon`.
 *
 * An explicit href is the escape hatch for a target the entity's own
 * resolver cannot know: somewhere external, somewhere deep, somewhere else
 * entirely. It is stored, it ages, and that is the consumer choosing it with
 * their eyes open rather than the package choosing it for them.
 *
 * ## The name is a reuse, and the reuse is the point
 *
 * A `FeedLink` existed until 2026-09-05 and was deleted with the older
 * `toFeedLink()` contract. {@see FeedMedia} records why: it "stopped being
 * 'a link' once it grew a label and a modal flag". It died of scope creep,
 * and the name is taken back here for the thing it always meant.
 *
 * WHICH IS THE TRIPWIRE. This class is a label and an optional href. The day
 * one is proposed for it — a modal flag, an icon, a target attribute, a
 * "variant" — the name is wrong again and the same class is being rebuilt.
 * The correct response to that proposal is not a fourth field.
 *
 * Nothing ever stored the old one, so there is no repeat of the detail
 * vocabulary fork, where two same-named classes held different shapes in
 * rows that were already written.
 *
 * ## Not FeedResource, which is also an AS2 Link
 *
 * {@see FeedResource} models the same AS2 term and the two divide cleanly:
 * a resource is a FILE you fetch and its href is required; a link is a PLACE
 * you go and its href is optional, because the commonest place is the entity
 * the block is already attached to. A resource carries `mediaType` because
 * something has to be decided before it is opened; a link carries none,
 * because navigating is not fetching.
 *
 * ## The label is the title, not a verb
 *
 * The misuse, named at birth the way `preview`'s was named too late:
 *
 *     FeedLink::make('Open the conversation', $url)      // ← the defect, back
 *
 * A label that says what a reader should DO is a call to action wearing a
 * title's clothes, and it reads as one the moment two rows carry it. The
 * label names the thing; the fact that it is a link is what says a tap goes
 * somewhere. If the only honest label is a verb, the row does not want a
 * link — it wants a different sentence.
 *
 * Core owns this payload slot, so no detail discriminator or storage version
 * travels with it.
 */
final readonly class FeedLink
{
    use HasPayload;

    public function __construct(
        public string $label,
        public ?string $href = null,
    ) {}

    /**
     * @param  string  $label  the text a reader sees — the thing's name, never an instruction
     * @param  string|null  $href  where a tap goes; null resolves to the entity's own url at read time
     */
    public static function make(string $label, ?string $href = null): self
    {
        return new self($label, $href);
    }

    /**
     * Rehydrate from a stored value, or null if it is not one of these.
     *
     * A PLAIN STRING IS NOT A LINK AND MUST NOT BECOME ONE. A field that
     * accepts `string|FeedLink` uses the two to mean different things: text
     * that leads nowhere, and text that leads to the entity. Converting a
     * string here would make every unlinked title clickable the moment its
     * field widened, which is the same defect as an available picture being
     * read as an instruction to draw one. The caller keeps the union; this
     * method only ever answers about a link.
     *
     * Anything else is null: a malformed stored value renders as nothing,
     * never as a broken row. Same rule as an unknown detail.
     */
    public static function from(mixed $value): ?self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $label = $value['label'] ?? null;

        if (! is_string($label) || $label === '') {
            return null;
        }

        $href = $value['href'] ?? null;

        return new self($label, is_string($href) && $href !== '' ? $href : null);
    }

    /** @return array{label: string, href: string|null} */
    public function toPayload(): array
    {
        return [
            'label' => $this->label,
            'href' => $this->href,
        ];
    }
}
