<?php

namespace Storyfeed\Body;

use LogicException;
use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\FeedLink;
use Storyfeed\FeedResource;
use Storyfeed\MediaSlot;

/**
 * The shape of a post: a title line, some prose, one picture, the files it
 * names, and a line of small print. Every field is optional, and the picture
 * is a reference rather than an image.
 *
 *     public function toFeed(): FeedEntity
 *     {
 *         return FeedEntity::make($this->name, data: MediaObject::make(
 *             subject: $this->name,
 *             content: $this->summary,
 *             image:   $this->feedMediaIcon(),
 *         ));
 *     }
 *
 * Stored:
 *
 *     {"$body": "Storyfeed/MediaObject", "$v": 1,
 *      "subject": "N201 Saffron Butter Rice",
 *      "content": "Basmati replaces Jasmine.",
 *      "image": "icon", "attachments": [], "footnote": "Approved by Jasper"}
 *
 * …and with a subject that leads to its entity, and a file it names itself:
 *
 *     {"$body": "Storyfeed/MediaObject", "$v": 1,
 *      "subject": {"label": "N201 Saffron Butter Rice", "href": null},
 *      "content": "Basmati replaces Jasmine.",
 *      "image": "icon",
 *      "attachments": [{"type": "Document", "href": "https://…/n201-v4.pdf",
 *                       "mediaType": "application/pdf", "name": "n201-v4.pdf"}],
 *      "footnote": null}
 *
 * ## It stores no media, it names a slot
 *
 * `FeedImage` is what `feedMedia()` RETURNS and not what `toFeed()` stores:
 * a src ages, so the location is resolved at read time. A detail is
 * stored, so a `MediaObject` holding a `FeedImage` would store exactly the
 * URL that rule forbids. It holds a slot name instead — `image: "icon"` —
 * and no src, no mediaType, no width, no height, no alt. The `FeedImage`
 * that `feedMedia()` resolves carries all of those; a copy here would be a
 * second copy that ages exactly as the URL would. At read time the renderer
 * takes `entity.media.icon`, already resolved, already beside this block in
 * the payload, and draws it with a live aspect box and live alt.
 *
 * The consequence worth having: changing a thumbnail conversion does not
 * rewrite history. Raise a THUMB_SIZE and every historical row draws the
 * new one, because none of them stored a size.
 *
 * ## `attachments` names its own files, and that costs something
 *
 * `attachments` is a list of {@see FeedResource} values: the files THIS
 * block names. It was a bool until 2026-09-10, where `true` meant "draw
 * whatever `entity.media.attachments` holds" — the paragraph above applied
 * one slot over, storing nothing and therefore ageing not at all.
 *
 * What it could not do was say WHICH. A block that defers to the entity
 * draws whatever the entity happens to hold at read time, so two blocks on
 * the same entity can never differ, and neither one can say what it was
 * about. The consumer says what a block CONTAINS; the renderer decides how
 * it looks. So the files are named here.
 *
 * THE COST IS REAL AND IS NOT HIDDEN. A `FeedResource` carries an href and
 * a name, and both are copies that age exactly as the paragraph above says
 * a src does: renamed files, moved disks, expired signatures. The trade,
 * taken with eyes open, is per-block choice of which files against rows
 * that go stale. It narrows *store a reference, not a copy* for this field
 * alone — `image` still names a slot and still stores nothing at all.
 *
 * A `FeedResource` is a VALUE, so a list of them is fine in every position:
 * the rule details answer to is that a body may hold a list of values, and
 * may not hold a list of bodies.
 *
 * ## `footnote` — small print, and the name is the constraint
 *
 * A line under the content, drawn small and muted by a renderer; this
 * package only stores it. The case it exists for is an approval that has to
 * be RECORDED without claiming equal weight with the sentence — subtle,
 * which is the word that was used for it and is the whole specification.
 *
 * It takes a string or a {@see FeedLink}, on `subject`'s rule exactly, so
 * that the credit can lead to the person or to the approval:
 *
 *     footnote: 'Approved by Jasper'
 *     footnote: FeedLink::make('Approved by Jasper', $approval->url)
 *
 * A plain-string footnote never becomes a link, for the same reason a
 * plain-string subject does not.
 *
 * THE NAME IS THE TRIPWIRE, the one {@see FeedLink} carries too. A footnote
 * that grows a picture, a second line or a heading has stopped being a
 * footnote: it is a block asking to be born, and the correct response to
 * that proposal is not a second thing in this field.
 *
 * There is no industry term to borrow, which is why the name is ours. AS2's
 * nearest is `attributedTo`, an entity reference and not text. schema.org's
 * `creditText` is scoped to media credit. HTML's `<small>` has the right
 * semantics — "side comments and small print, including… attribution" — but
 * it is an element, not a field name. The name was chosen for what it forbids.
 *
 * ## The three slots, by example — the slot IS the meaning
 *
 * The values are Activity Streams 2.0's own property names, already the
 * keys of `entity.media`, and a consumer who has set entity media has
 * already chosen between them once. The slot answers what the media IS to
 * the entity, and the rule that falls out of the examples below: media
 * should be OF the entity, unless the slot is `icon`, which may represent
 * by association.
 *
 * A consumer's production feed showed why the distinction earns a slot.
 * Three rows in one viewport, about the same menu item, all drawing the
 * identical plated-dish photograph at the same size: "Nangi replied on
 * N201", "Nayani added a photo of N201", "Nayani wrote a note on N201".
 * Only the middle row is ABOUT the photograph. The other two are a reply
 * and a note whose object is a discussion, and the app had put the dish's
 * lead image in the discussion's `preview` — but a photograph of food is
 * not a preview of a discussion.
 *
 * **`icon` — which thing this is.** The reply and the note. A discussion
 * has no picture of its own, but "what small image represents this
 * discussion?" has an answer: the dish it concerns. `icon` is small and
 * REPRESENTATIONAL, and a representation may be borrowed from what the
 * thing is about. So the comment-shaped rows name `icon`, and the same
 * photograph reads as a 32px identifier beside the text — the way it
 * labels the dish in the menu list — rather than as a photograph under a
 * reply.
 *
 *     MediaObject::make(subject: $note->title, content: $note->body, image: $note->feedMediaIcon())
 *
 * **`image` — what the thing looks like.** The photo row. "Nayani added a
 * photo of N201" is about the picture, and the dish is a non-image object
 * of which the photograph is a larger visual representation. The block
 * reads as the post it is — the name, the caption, and the picture worth
 * stopping on, at the size the feed gives a picture. Same rows, same
 * photograph, and only this one stays a photograph.
 *
 *     MediaObject::make(subject: $dish->name, content: $photo->caption, image: $dish->feedMediaImage())
 *
 * **`preview` — a stand-in that previews the thing without depicting it.**
 * A link card. An app stores a URL, scrapes its og:title, og:description
 * and og:image on its own schedule, and its `toFeed()` is this body type with
 * no field left over and none missing: `subject` is the title, `content`
 * the description, and the og:image is the `preview` — it does not depict
 * the page's content, it stands in for it, which is AS2's "an entity that
 * provides a preview of this object" to the letter. Not `icon` (there is
 * no identity mark) and not `image` (it is not a visual representation of
 * the thing itself). Every reader has seen this card in a chat app, so the
 * case is recognised rather than taught.
 *
 *     MediaObject::make(subject: $link->og_title, content: $link->og_description, image: $link->feedMediaPreview())
 *
 * THE PACKAGE FETCHES NOTHING. The app scraped and cached those values
 * before it recorded the row; the resolver turns the cached og:image into a URL
 * into `preview` at read time; the renderer draws what it is handed. Same
 * line {@see File} draws — it is not the remote-resource hazard `Link` and
 * `Media` are, because nothing here issues a request — and this example
 * is not an invitation to make a feed render fetch a page.
 *
 * And the misuse, named: `preview` is the slot people reach for when the
 * other two do not obviously fit. That is how the three-photographs row
 * above happened — nothing about a reply has a preview, and a plated dish
 * is not a stand-in for a discussion. A preview previews THIS entity; if
 * the picture depicts something else, the slot is `icon` or the picture
 * does not belong on the row.
 *
 * A row's picture is `icon` when it labels, `image` when it represents,
 * `preview` when it stands in. `url` cannot be named: it is where the tap
 * goes, not a picture of the thing.
 *
 * ## The subject may be the way in
 *
 * `subject` takes a string or a {@see FeedLink}, and the two mean different
 * things: text that leads nowhere, and text that leads somewhere.
 *
 *     subject: $dish->name                                  // a title
 *     subject: FeedLink::make($dish->name)                  // → this entity
 *     subject: FeedLink::make($n->title, $n->external_url)  // → there
 *
 * A LINK WITH NO HREF IS THE ONE TO REACH FOR. It stores no location; the
 * renderer resolves it against the entity's own url, resolved at read time,
 * the same way `image: "icon"` resolves against `entity.media.icon`. An
 * explicit href is stored and ages, and is for a target the entity's
 * resolver cannot know.
 *
 * IT EXISTS SO NOBODY HAS TO OPEN A VIEW FILE. A consumer who needs a way
 * into the thing a row is about, and has only a renderer's markup to put it
 * in, ships a bordered card containing the words *Open the conversation* —
 * and three of those in one viewport outweigh the words they are a way into.
 * The affordance is not a second thing on the row; it is a property of the
 * title that was already there.
 *
 * A plain-string subject never becomes a link. Widening this field must not
 * make every title written before it clickable — an available target is not
 * an instruction, the same rule the picture slots answer to.
 *
 * ## At most one slot, and a second one throws
 *
 * A block naming two slots is a block asking to be drawn twice. `make()`
 * takes one `image`; the fluent `withIcon()` / `withPreview()` /
 * `withImage()` set the same field and throw a `LogicException` if it is
 * already set, rather than replacing it. Last-wins would turn an authoring
 * mistake into a silent layout, at the one moment — record time — where
 * the author is present to hear about it. Unknown methods are errors here,
 * not features; a second slot is the same kind of thing.
 *
 * Fluent and named forms produce byte-identical rows — the fluent form is
 * sugar, never a second body type:
 *
 *     MediaObject::make(subject: $name)->withIcon()->withAttachments($pdf)
 *     MediaObject::make(subject: $name, image: MediaSlot::Icon, attachments: [$pdf])
 *
 * ## A block naming an empty slot draws nothing
 *
 * Naming a slot does not check that the entity's resolver fills it. If
 * `feedMedia()` never sets `icon`, a block saying `image: "icon"` draws its
 * subject and content and no picture — correct, and silent, the same rule
 * as an unknown detail. That mismatch is statically knowable without any
 * traffic, and it is a doctor check's job, not a renderer's.
 *
 * ## Only on an entity, for now
 *
 * The reference is unambiguous on an ENTITY: "my icon". In an activity's
 * own `data` it would have to say whose — the object's or the target's —
 * and this version does not. A renderer that meets one there has no
 * entity to read a slot from, and draws the text and nothing else. `$v`
 * exists for the day the block learns to say whose.
 *
 * ## Not `Excerpt`, not `Change`, not `Prose`, not a thread
 *
 * Most app data fits this shape, which is its use and its hazard. It can
 * express the other body types badly, and nothing stops a consumer doing so.
 *
 * `content` is PROSE, plain text, escaped on the way out: a description, a
 * caption, a one-line reason. A quotation with a source is {@see Excerpt}
 * — the tell is that the words are someone else's. Authored rich text is
 * {@see Prose}, which says so and is sanitised at read time; prose
 * pasted into `content` renders as its own asterisks. A field that was one
 * thing and is now another is {@see Change}, and a conversation is core's
 * `FeedThread`, painted by the presenter — the tell is a reply count. A
 * list of files BESIDE a sentence is `attachments`; ONE artefact whose own
 * facts are the row — how big it is, what type it is — is {@see File}, and
 * a `MediaObject` carrying a single attachment and nothing else is usually
 * a `File` written the long way.
 *
 * A `subject` that repeats the headline is the smell the other body types name
 * too: a preview complements the sentence above it.
 *
 * IT IS ADVICE, AND A RENDERER MUST NOT ENFORCE IT. One drew the subject only
 * when it differed from the entity's label, which silently deleted a title an
 * app had supplied — and, once the subject could link, an affordance with it.
 * The owner's ruling: *"if a consumer describes a media object with a subject,
 * they expect to see that subject rendered in the media object."* A renderer
 * deciding whether an app's content is legitimate is the same posture that was
 * removed from the object icon the same day.
 *
 * The version travels in both storage and payload: core does not own the
 * app's key, so the renderer must upgrade the detail at read time, never
 * write it back.
 */
class MediaObject implements FeedBody
{
    use HasPayload;

    /**
     * @param  list<FeedResource>  $attachments
     */
    final protected function __construct(
        private readonly string|FeedLink|null $subject,
        private readonly ?string $content,
        private readonly ?MediaSlot $image,
        private readonly array $attachments,
        private readonly string|FeedLink|null $footnote,
    ) {}

    /**
     * @param  string|FeedLink|null  $subject  a title line — only when the headline does not already say it; a {@see FeedLink} makes it the row's way in
     * @param  string|null  $content  prose, as plain text
     * @param  MediaSlot|null  $image  which of the entity's media slots is this block's picture
     * @param  array<array-key, mixed>  $attachments  the files this block names, as {@see FeedResource} values
     * @param  string|FeedLink|null  $footnote  small print under the content — a credit, an approval; never a second paragraph
     */
    public static function make(
        string|FeedLink|null $subject = null,
        ?string $content = null,
        ?MediaSlot $image = null,
        array $attachments = [],
        string|FeedLink|null $footnote = null,
    ): static {
        return new static(
            $subject,
            $content,
            $image,
            array_values(array_filter($attachments, fn (mixed $file): bool => $file instanceof FeedResource)),
            $footnote,
        );
    }

    /** The picture is the entity's `icon` — which thing this is. */
    public function withIcon(): static
    {
        return $this->naming(MediaSlot::Icon);
    }

    /** The picture is the entity's `preview` — a stand-in that previews the thing without depicting it. */
    public function withPreview(): static
    {
        return $this->naming(MediaSlot::Preview);
    }

    /** The picture is the entity's `image` — what the thing looks like. */
    public function withImage(): static
    {
        return $this->naming(MediaSlot::Image);
    }

    /**
     * Name the files this block draws, replacing any already named.
     *
     * At least one is REQUIRED, so that the day the bool was retired lands
     * as an error in the fluent form too. `withAttachments()` meaning "draw
     * the entity's files" is the shape that went away; accepting the same
     * call and quietly producing an empty list would make an upgrade look
     * like it worked.
     */
    public function withAttachments(FeedResource $file, FeedResource ...$more): static
    {
        return new static($this->subject, $this->content, $this->image, [$file, ...$more], $this->footnote);
    }

    /**
     * `Storyfeed/Body/MediaObject` — the VOCABULARY'S name, not a package's.
     *
     * A detail outlives whichever library defined it ({@see FeedBody}), so the
     * name must not contain the library: this body type has already moved
     * packages once, and a `storyfeed-ui/` or `storyfeed-filament/` prefix
     * would have moved with it. The name is a pure lookup key — no reflection,
     * no autoloading — so it need not resolve to anything. PascalCase matches
     * AS2's own type casing, which the payload already carries (`FeedResource`
     * → `type: "Document"`), and a lowercase `vendor/name` reads as a Composer
     * package, which is the misreading that produced the earlier fork.
     * Renderers match it EXACTLY, so the casing is part of the name.
     */
    public static function name(): string
    {
        return 'Storyfeed/Body/MediaObject';
    }

    public static function version(): int
    {
        return 1;
    }

    public static function upgrade(array $payload, int $from): array
    {
        // Total by contract: a row from a version this class does not know
        // still has to render. A slot name it does not know — a case a later
        // version added, or `url`, which is never a slot — is null, so the
        // text draws and the picture does not, rather than a renderer being
        // asked for a slot this vocabulary never issued.
        $image = $payload['image'] ?? null;

        // A SUBJECT IS TEXT OR A LINK, and the two are not interchangeable.
        // A string stays a string: widening the field must not turn every
        // title written before this class existed into a clickable one. The
        // footnote answers to the same rule and for the same reason.
        $subject = $payload['subject'] ?? null;
        $footnote = $payload['footnote'] ?? null;

        /*
         * `attachments` WAS A BOOL, and an old `true` upgrades to an empty
         * list: the row that used to defer to the entity now names no files
         * and draws none. It does not throw and it is not migrated — nothing
         * already written becomes invalid, it becomes empty, which is a state
         * this vocabulary already has and already renders as nothing. The
         * consumer re-authors the block the next time they touch it.
         */
        $attachments = $payload['attachments'] ?? null;

        return [
            'subject' => is_string($subject) ? $subject : FeedLink::from($subject)?->toPayload(),
            'content' => is_string($payload['content'] ?? null) ? $payload['content'] : null,
            'image' => is_string($image) ? MediaSlot::tryFrom($image)?->value : null,
            'attachments' => array_values(array_filter(
                array_map(self::resource(...), is_array($attachments) ? $attachments : []),
                is_array(...),
            )),
            'footnote' => is_string($footnote) ? $footnote : FeedLink::from($footnote)?->toPayload(),
        ];
    }

    /**
     * @return array{'$body': string, '$v': int, subject: string|array{label: string, href: string|null}|null, content: string|null, image: string|null, attachments: list<array{href: string, mediaType: string|null, name: string|null, type: string}>, footnote: string|array{label: string, href: string|null}|null}
     */
    public function toPayload(): array
    {
        return [
            self::KEY => self::name(),
            self::VERSION => self::version(),
            'subject' => $this->subject instanceof FeedLink ? $this->subject->toPayload() : $this->subject,
            'content' => $this->content,
            'image' => $this->image?->value,
            'attachments' => array_map(fn (FeedResource $file): array => $file->toPayload(), $this->attachments),
            'footnote' => $this->footnote instanceof FeedLink ? $this->footnote->toPayload() : $this->footnote,
        ];
    }

    /**
     * Rehydrate one stored file, or null if it is not one.
     *
     * An href is the one thing a resource cannot do without, so a value
     * missing it is dropped rather than rendered as a link to nowhere — the
     * same rule as a malformed subject. What survives is rebuilt through
     * {@see FeedResource} so an upgraded row carries exactly the shape and
     * key order {@see toPayload()} writes, rather than whatever the stored
     * value happened to have.
     *
     * @return array{href: string, mediaType: string|null, name: string|null, type: string}|null
     */
    private static function resource(mixed $value): ?array
    {
        if ($value instanceof FeedResource) {
            return $value->toPayload();
        }

        if (! is_array($value)) {
            return null;
        }

        $href = $value['href'] ?? null;

        if (! is_string($href) || $href === '') {
            return null;
        }

        $type = $value['type'] ?? null;

        return FeedResource::make(
            href: $href,
            mediaType: self::text($value['mediaType'] ?? null),
            name: self::text($value['name'] ?? null),
            type: is_string($type) && $type !== '' ? $type : 'Document',
        )->toPayload();
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function naming(MediaSlot $slot): static
    {
        if ($this->image !== null) {
            throw new LogicException(sprintf(
                'A MediaObject names at most one image slot; this one already names `%s` and cannot also name `%s`.',
                $this->image->value,
                $slot->value,
            ));
        }

        return new static($this->subject, $this->content, $slot, $this->attachments, $this->footnote);
    }
}
