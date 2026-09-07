<?php

namespace Storyfeed;

use Storyfeed\Concerns\HasPayload;

/**
 * The utterance a feed row is about, and the size of the conversation
 * around it.
 *
 *     Storyfeed::activity('reply', $comment)
 *         ->by($user)
 *         ->on($ticket)
 *         ->thread(FeedThread::make(
 *             text: $comment->body,
 *             kind: 'replied',
 *             replies: $ticket->comments()->count(),
 *             truncated: true,
 *         ))
 *         ->publish();
 *
 * ## The bug that produced it (2026-09-06)
 *
 * A consumer's row said "· 1 reply" in its headline and quoted, underneath,
 * a passage that was NOT the reply — it was the thread's opening question.
 * The count came out of a grammar closure; the quote came out of a renderer
 * detail. TWO HOMES, NO SHARED TRUTH, so they could disagree, and did. The
 * fix is not a better closure: it is one object that carries both facts, so
 * a renderer painting the count and a renderer painting the quote are
 * reading the same row of the same table.
 *
 * ## It is ACTIVITY-scoped, and that is the load-bearing decision
 *
 * The utterance to show DIFFERS PER ROW for one and the same thread. An
 * "opened" row shows the opening question. A "replied" row shows the newest
 * reply. A "settled" row shows the opening again, labelled as the question
 * it answered. An entity-scoped object — one hung off the ticket, or off the
 * thread's snapshot — could not express that: it would carry one utterance
 * for every row about that thread, and the first feed with two rows would
 * make one of them wrong. So it is set on the ACTIVITY
 * ({@see PendingActivity::thread()}) and rides the node as `thread`.
 *
 * ## `kind` is the consumer's word, not an enum
 *
 * Core cannot know whether a domain calls it "asked", "raised" or "flagged",
 * and a fixed vocabulary would be wrong in the first app that needed a
 * fourth word. Same reasoning as verbs staying free-form strings in storage,
 * and the same as `Detail\Excerpt`'s `from`.
 *
 * ## Which do I use — this or `Detail\Excerpt`?
 *
 * They quote text and they are not the same tool.
 *
 * `Detail\Excerpt` (paid Filament adapter) is the GENERIC one-passage form,
 * hung off an ENTITY's snapshot data: a fragment of a document, and where it
 * came from. It is renderer vocabulary, it has no AS2 term, and it stays
 * exactly what it was — this class does not replace it.
 *
 * `FeedThread` is core vocabulary for a CONVERSATION. Reach for it when the
 * row is about something someone SAID and there is a thread around it whose
 * size the reader is meant to see. The tell is `replies`: if there is no
 * conversation to count, you want an excerpt. The second tell is scope — an
 * excerpt describes an entity wherever that entity appears, this describes
 * one activity and only that one.
 *
 * ## What this class does NOT do
 *
 * It does not truncate. The consumer caps `text` at whatever boundary its
 * domain wants (core has no business finding sentence ends in someone
 * else's prose), and `truncated` only tells a renderer whether to mark it.
 *
 * It does not render, and it does not know the row's actor. Suppressing
 * `by` when it repeats the actor already named in the headline needs the
 * actor, which only a presenter has — so that, the quote block and the
 * "{by} {kind}" line are all the renderer's.
 *
 * Part of the versioned payload contract (docs/payload.md, `thread`).
 */
final class FeedThread
{
    use HasPayload;

    /**
     * The reserved key this rides under inside the activity's `data`
     * column, `$`-prefixed as the adapter's `$detail` is: `data` is the
     * app's map and a package that stores in it must be unmistakable about
     * which key is not the app's. It is stripped back out on the read path,
     * so the payload's `data` is exactly what the app recorded.
     */
    public const KEY = '$thread';

    /**
     * The reserved key carrying the STORED version, spelled as the adapter's
     * `Detail::VERSION` is — two values sharing one `data` column follow one
     * rule, or the rule is not a rule.
     *
     * It rides the column and it does NOT ride the payload: see
     * {@see toPayload()}.
     */
    public const string VERSION = '$v';

    public function __construct(
        public private(set) string $text = '',
        public private(set) ?string $by = null,
        public private(set) ?string $kind = null,
        public private(set) ?int $replies = null,
        public private(set) bool $truncated = false,
    ) {}

    /**
     * @param  string  $text  the utterance to show
     * @param  string|null  $by  its author, when the sentence above does not already name them
     * @param  string|null  $kind  the consumer's word for the act: 'asked', 'replied', 'decided'
     * @param  int|null  $replies  the size of the conversation, or null when nobody counted
     * @param  bool  $truncated  whether `text` is a fragment of something longer
     */
    public static function make(
        string $text = '',
        ?string $by = null,
        ?string $kind = null,
        ?int $replies = null,
        bool $truncated = false,
    ): self {
        return new self($text, $by, $kind, $replies, $truncated);
    }

    public function text(string $text): self
    {
        $this->text = $text;

        return $this;
    }

    public function by(?string $by): self
    {
        $this->by = $by;

        return $this;
    }

    public function kind(?string $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function replies(?int $replies): self
    {
        $this->replies = $replies;

        return $this;
    }

    public function truncated(bool $truncated = true): self
    {
        $this->truncated = $truncated;

        return $this;
    }

    /**
     * The version this class writes today.
     *
     * `Detail`'s rule 1, and it is about the COLUMN rather than the contract:
     * a row recorded today outlives the class that recorded it, and "we will
     * add the version later" is provably wrong because later, the
     * unversioned rows already exist. They do — this class shipped without a
     * version on 2026-09-06 and rows from that day are in production.
     */
    public static function version(): int
    {
        return 1;
    }

    /**
     * Which version a stored value describes itself as.
     *
     * **A MISSING `$v` IS VERSION 1. That is a definition, not a fallback.**
     * Every row written between 2026-09-06 and this commit carries no version
     * key, and those rows are version 1 — there is no other thing they could
     * be. Reading them as {@see version()} instead ("assume it is current")
     * would be silently right today and silently wrong the day a version 2
     * lands, because the legacy rows would then skip the 1→2 upgrade with
     * nothing to notice it. So the 1 is written here, once, and never
     * derived.
     *
     * A non-integer `$v` is not a version either, and reads as 1 for the same
     * reason the rest of the read path is total: the row is in the database
     * regardless.
     */
    public static function versionOf(mixed $value): int
    {
        if (! is_array($value)) {
            return 1;
        }

        $version = $value[self::VERSION] ?? null;

        return is_int($version) && $version > 0 ? $version : 1;
    }

    /**
     * Normalize a stored payload of version `$from` into the current shape.
     *
     * Called at READ time and never persisted back — `Detail`'s rule 2. The
     * point is that every renderer sees ONE shape forever, so no Blade, Vue
     * or Filament view ever branches on `$v`. Core owns this class and
     * upgrades before anyone downstream looks, which is why the payload does
     * not carry the version at all ({@see toPayload()}).
     *
     * TOTAL, like {@see fromArray()}: an unrecognised `$from` — a row written
     * by a NEWER core than the one reading it — returns the payload untouched
     * and lets the field-by-field defensive read in `fromArray()` take it
     * from there. A feed that 500s on a shape it does not know is worse than
     * one that renders the parts it recognises.
     *
     * There is only version 1, so today every branch is the identity. The
     * seam exists now because it cannot exist later.
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public static function upgrade(array $payload, int $from): array
    {
        return $payload;
    }

    /**
     * Rebuild from what the column holds, or null when it holds nothing
     * usable.
     *
     * TOTAL BY CONTRACT, for the reason `Detail::upgrade()` is total: the row
     * is in the database either way, and a feed that 500s on a payload some
     * earlier version wrote is worse than one that reads a stray value as
     * absent. Anything that is not an array is not a thread; a missing or
     * non-string `text` degrades to empty rather than throwing, and a
     * non-integer `replies` degrades to null — "nobody counted" — rather
     * than to a fabricated zero, which would print "0 replies" as a fact.
     */
    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value)) {
            return null;
        }

        $value = self::upgrade($value, self::versionOf($value));

        $replies = $value['replies'] ?? null;

        return new self(
            text: is_string($value['text'] ?? null) ? $value['text'] : '',
            by: is_string($value['by'] ?? null) ? $value['by'] : null,
            kind: is_string($value['kind'] ?? null) ? $value['kind'] : null,
            replies: is_int($replies) ? $replies : null,
            truncated: (bool) ($value['truncated'] ?? false),
        );
    }

    /**
     * The STORAGE shape: the five keys, plus the version that says which five
     * they are.
     * This is the storage-only override of the rule in
     * {@see HasPayload}: payload is the base, storage
     * enriches it, and storage internals never become node keys by default.
     *
     * This is what goes into the `data` column under {@see KEY}, and the
     * version is in it from this commit on because storage is the thing that
     * outlives the class. The storage location was never part of the payload
     * contract (docs/payload.md says so in as many words), so this key is
     * ours to add.
     *
     * @return array{text: string, by: string|null, kind: string|null, replies: int|null, truncated: bool, '$v': int}
     */
    public function toArray(): array
    {
        return [
            ...$this->toPayload(),
            self::VERSION => self::version(),
        ];
    }

    /**
     * The PAYLOAD shape: the same five keys a renderer has always read, and
     * **no `$v`**.
     *
     * The version does not travel, and this is the load-bearing half of the
     * change. It differs from `Detail`, where `$v` reaches the renderer
     * because the renderer does the upgrading — core owns `FeedThread` and
     * upgrades on read ({@see upgrade()}), so a renderer is handed the
     * current shape by construction and has nothing to ask. A `$v` on
     * `node.thread` would be a sixth key every renderer in existence gains,
     * a key none of them can act on, and — once written into a contract that
     * freezes at v0.3 — one nobody can take back.
     *
     * Every key is always present: a renderer reads a missing fact as null
     * rather than as an undefined index, exactly as `FeedImage` does.
     *
     * @return array{text: string, by: string|null, kind: string|null, replies: int|null, truncated: bool}
     */
    public function toPayload(): array
    {
        return [
            'text' => $this->text,
            'by' => $this->by,
            'kind' => $this->kind,
            'replies' => $this->replies,
            'truncated' => $this->truncated,
        ];
    }
}
