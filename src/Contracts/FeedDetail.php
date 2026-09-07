<?php

namespace Storyfeed\Contracts;

use Illuminate\Contracts\Support\Arrayable;
use Storyfeed\FeedThread;

/**
 * A recognised form a value inside `data` can take, so that a renderer can
 * draw it without knowing the app that recorded it.
 *
 * `data` is the app's map and this package hands it over without reading it,
 * which is right and is not changing. But it leaves every consumer in the same
 * place — a payload of loose keys and a view to write before anything appears
 * on screen. A DETAIL is app data with a conventional form: the app writes its
 * sentence once at record time, where the domain knowledge already is, and any
 * renderer that recognises the form draws it with no view at all.
 *
 *     $ticket->recordActivity('revise')->data([
 *         'reason' => $reason,
 *         'diff' => Change::make(['Status' => ['Draft', 'Ready']])->toArray(),
 *     ])->publish();
 *
 * ## Core owns the SPEC and nothing else
 *
 * There is no `FeedDetail` implementation in this package and there will not
 * be one. The first library of prefabricated details is `storyfeed/ui` (free,
 * MIT); the paid Filament adapter renders any conforming detail by shape; an
 * app may write its own and owe nothing to either. That is only possible if
 * the thing they agree on is an interface rather than a class — a renderer
 * depending on core can draw a form defined by a package it has never heard
 * of, and a detail outlives whichever library defined it.
 *
 * So core learns NO NAME and NO SHAPE. Nothing here is a registry, nothing
 * validates a token against a list, and `docs/payload.md` grows no key: a
 * detail is stored as the array it produces, in the app's own map, and comes
 * back out of the read path byte-identical. An activity recorded from a detail
 * is indistinguishable from one recorded from the array that detail produces.
 * That is what lets the vocabulary evolve on a library's timeline instead of
 * being frozen with the payload.
 *
 * ## The versioning rule, and why it is the OPPOSITE of FeedThread's
 *
 * Two versioning postures live in the same `data` column, and read side by
 * side they look like an inconsistency until you know the branch. The branch
 * is one question:
 *
 * **DOES CORE OWN THE KEY? If yes, core normalizes and the version never
 * reaches the renderer. If no, the version travels and the RENDERER upgrades.**
 *
 * {@see FeedThread} is the first case: it lands at a fixed reserved
 * key (`$thread`), so the read path can find it, upgrade it, strip its `$v`
 * and emit one shape forever — a renderer never learns that core versions
 * anything.
 *
 * A detail is the second case, and the difference is STRUCTURAL rather than a
 * preference. A detail lands at an APP-CHOSEN key inside the app's own map, so
 * core cannot find it to normalize it: a reader has to walk `data` looking for
 * {@see KEY}, and core does not walk the app's data. Therefore {@see VERSION}
 * travels all the way to the renderer, and the renderer calls
 * {@see upgrade()} before it draws.
 *
 * This paragraph is the point of writing it here rather than in a design doc:
 * it is what stops someone later "fixing" one of the two to match the other.
 * Neither is the mistake. They are the same rule applied to a key core owns
 * and a key it does not.
 *
 * ## Four rules
 *
 * 1. **Versioned from the first commit.** Not about the contract — about the
 *    COLUMN. A row recorded today outlives the class that recorded it, and a
 *    v1 row will still be in that table when the vocabulary is on v3. "We will
 *    add the version later" is provably wrong: later, the unversioned rows
 *    already exist. `FeedThread` shipped without a version on 2026-09-06 and
 *    spent a commit the following day defining what its absence meant.
 * 2. **Upgraded at READ time** ({@see upgrade()}), never written back. Every
 *    renderer sees the current form, so there is one render path per detail
 *    forever. The alternative — each renderer branching on `$v` — multiplies
 *    that branching across Blade, Vue, Filament and everything after them.
 * 3. **An unknown detail renders as NOTHING, and never as an error.** The same
 *    rule this package already applies to unknown verbs and to extension
 *    types, and the same reason: activities are never withheld by the read
 *    path. It covers version skew too — an app on a newer vocabulary than the
 *    renderer reading it is a blank space, not a broken feed.
 * 4. **Details never nest.** With a one-to-one component mapping the word
 *    "block" was defensible, but people expect blocks to nest, and the moment
 *    they do, `data` is a template and the activity row is a view file. The
 *    payload's headline is a SENTENCE rather than a structure for exactly this
 *    reason.
 *
 * ## A detail names a FORM, not a component
 *
 * `Markdown` names an encoding, which is genuinely part of the data.
 * `Blockquote` would name markup, which is not: `<blockquote>` is how a
 * passage happens to be drawn in one renderer, and every later renderer would
 * inherit a decision made for that one.
 *
 * ## What core does NOT do with any of this
 *
 * It does not read a detail, strip one, upgrade one, count one, or mention one
 * in a payload key or an Activity Streams document. `storyfeed:doctor`'s
 * `details` check reads the column and reports what it finds, which is the one
 * place core looks at a detail at all — and it reports only what is knowable
 * without a vocabulary, because core having a vocabulary is the thing this
 * interface exists to avoid.
 */
interface FeedDetail extends Arrayable
{
    /**
     * The reserved key naming the form.
     *
     * `$kind`, `$type`, `$component` and `$template` are all taken by the
     * payload contract in the same JSON document, and `form`, `schema`,
     * `section` and `widget` are Filament's. This collides with nothing.
     *
     * `$`-PREFIXED BECAUSE THE MAP IS THE APP'S. A package writing into
     * someone else's map must be unmistakable about which key is not theirs —
     * the same reason `$thread` is spelled that way. Core strips the reserved
     * keys core owns and passes every other one through untouched, which is
     * precisely what lets this one survive the read path.
     */
    public const string KEY = '$detail';

    /**
     * The reserved key carrying the version — see rule 1, and the versioning
     * rule above for who acts on it.
     *
     * Spelled as `FeedThread::VERSION` is: two kinds of value sharing one
     * `data` column follow one spelling, or the spelling is not a rule.
     *
     * **A MISSING VERSION IS 1. That is a definition, not a fallback**, and it
     * is the reader's rule as much as the writer's: hand-written arrays in
     * seeders exist, and rows written before a library added its version key
     * exist. Reading a missing version as "whatever is current" is silently
     * right today and silently wrong the day a v2 lands, because those rows
     * would then skip the 1→2 upgrade with nothing to notice it.
     */
    public const string VERSION = '$v';

    /**
     * This form's name, as it is written into storage.
     *
     * NAMESPACE IT TO THE LIBRARY that defines it — `storyfeed-ui/change`,
     * `acme/shipment`. The name outlives every class that writes it, so it
     * says whose vocabulary it is, and two libraries that both wanted the word
     * "change" do not collide in a column.
     *
     * Free-form, and core never validates it against anything — the same
     * doctrine as verbs staying free-form strings in storage. A renderer that
     * does not know a name draws nothing (rule 3); core that does not know a
     * name says nothing.
     */
    public static function name(): string;

    /** The version this class writes today. */
    public static function version(): int;

    /**
     * Normalize a stored payload of version `$from` into the current form.
     *
     * Called at READ time by the RENDERER and never persisted back — see rule
     * 2 and the versioning rule. Implementations must be TOTAL: an
     * unrecognised `$from` — a row written by a newer version of this library
     * than the one reading it — returns something renderable rather than
     * throwing, because the row is in the database either way.
     *
     * The `$payload` handed here excludes {@see KEY} and {@see VERSION}: the
     * reader has already used both to get this far, and a form should not have
     * to filter its own bookkeeping back out.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function upgrade(array $payload, int $from): array;
}
