<?php

namespace Storyfeed;

use Illuminate\Translation\MessageSelector;
use InvalidArgumentException;

/**
 * The plural forms of the thing a role holds — "clause|clauses" — and the
 * form selected for a count ("clauses").
 *
 * WHY THE `Feed` PREFIX AND THE ROOT NAMESPACE (2026-09-05, issue #7). This
 * is consumer-facing: `FeedNoun::trans('nouns.delivery')` is the documented
 * way to register a translated noun, and it sits in an application's
 * service provider next to `FeedEntity` and `FeedMedia`. A value object on
 * the contract surface carries the `Feed` prefix so that its `use` line
 * says whose it is — an unprefixed `Noun` reads like something the
 * framework provides — and it lives at the root because `Support\` reads
 * like plumbing, which tells the importer the wrong thing about what they
 * hold. A transcription under `ActivityStreams\` keeps W3C's bare spelling;
 * that is the other half of the same convention. It was `Support\Noun`
 * before the convention existed, and the break was taken while it cost one
 * `use` line.
 *
 * WHY A WRAPPER AND NOT A CONVENTION. A registry value is EITHER literal
 * plural forms or a translation key, and nothing about the string itself
 * says which: `'file'` is a plausible literal noun and a plausible key.
 * Sniffing (does it contain a dot? a pipe? does the translator know it?)
 * makes registration's meaning depend on what happens to be in the lang
 * files, so a new `file.php` translation file could silently rewrite a
 * headline. The wrapper is explicit and cannot drift:
 *
 *     Storyfeed::nouns([
 *         'clause' => 'clause|clauses',
 *         'document' => FeedNoun::trans('nouns.document'),
 *     ]);
 *
 * Literal forms go through Laravel's MessageSelector rather than an English
 * `Str::plural()`, so a locale with more than two forms is served by adding
 * segments: Polish `klauzula|klauzule|klauzul` selects correctly at
 * n = 1 / 2 / 5 / 22 (FeedNounTest proves it). Hand-rolled pluralisation
 * could never do that, and would be wrong in English soon enough anyway.
 *
 * THERE IS NO COUNTED PHRASE. `phrase()` — "1,204 clauses" — was removed
 * with the rename. The rung stopped printing a number (see form()), which
 * left it with no caller in core, and nothing in the docs ever taught it.
 * Its one claim to stay was the thousands grouping, and that grouping was
 * `number_format()`: "1,204" is right in English and wrong in Polish
 * ("1 204") and German ("1.204"), an English guess shipped on the app's
 * behalf — the exact thing this class refuses to do for plurals. An app
 * that wants the number said composes it with its own locale-aware
 * formatter: `Number::format($n).' '.FeedNoun::form($noun, $n)`.
 */
final class FeedNoun
{
    /**
     * The noun used when a type has none registered.
     *
     * A missing noun does NOT skip the rung: "updated items" is bland, but
     * the screen belongs to the reader while the nagging belongs to the
     * developer, and a true bland sentence beats a muted count label.
     */
    public const GENERIC = 'item|items';

    private function __construct(
        public readonly string $value,
        public readonly bool $translated,
    ) {}

    /**
     * Literal plural forms, e.g. 'clause|clauses'.
     *
     * BOTH FORMS ARE ALWAYS THE APP'S TO SUPPLY. The core does not inflect —
     * not with Str::plural(), not with anything. English inflection is
     * wrong often enough to matter ("terms sheet" pluralises on the head
     * noun, not the tail), it is wrong for every locale that is not English,
     * and a wrong plural is a wrong sentence in the reader's face. Reusing
     * the single form for every count is the same failure wearing a
     * politer face: "7 clause" is not a fallback, it is a typo the package
     * shipped on the app's behalf.
     *
     * So a value with no pipe is a REGISTRATION ERROR, thrown where the
     * developer is looking — at boot, in the file they just edited — rather
     * than surfacing months later inside one group node nobody scrolled to.
     */
    public static function of(string $forms): self
    {
        if (! str_contains($forms, '|')) {
            throw new InvalidArgumentException(
                "The noun [{$forms}] has only one form. Storyfeed never inflects — English "
                .'inflection is wrong often enough to matter and wrong everywhere else by '
                ."default — so give it both: ['{$forms}|{$forms}s']. Locales needing more "
                .'forms take more segments, and a translation key goes behind FeedNoun::trans().',
            );
        }

        return new self($forms, false);
    }

    /** A translation key, resolved through trans_choice() at render time. */
    public static function trans(string $key): self
    {
        return new self($key, true);
    }

    /** The form for a count: 1 => "clause", 7 => "clauses". */
    public function forCount(int $count): string
    {
        if (! $this->translated) {
            return (string) (new MessageSelector)->choose($this->value, $count, app()->getLocale());
        }

        /*
         * `:count` IS SUPPRESSED, not passed through. A noun is a noun — the
         * rung that renders this into a headline prints no number at all,
         * and a caller who wants one prepends it themselves. But Laravel's
         * trans_choice() adds `count` to the replacements for free, so a
         * translator who reasonably writes ":count clauses" used to get
         * "7 7 clauses" on the page. Overriding it with an empty string makes
         * that line render the noun alone instead of doubling the number, and
         * the collapse below removes the space it leaves behind.
         *
         * The alternative — letting a translation own the whole phrase — would
         * mean a literal and a key registered for the same type produce
         * different shapes, which is a worse thing to explain than this.
         */
        $phrase = (string) trans_choice($this->value, $count, ['count' => '']);

        return trim((string) preg_replace('/\s+/u', ' ', $phrase));
    }

    /**
     * "clauses" — the form that replaces an unpinned role token in a headline.
     *
     * THE COUNT SELECTS THE FORM AND IS NOT PRINTED (2026-09-05). The rung
     * used to hand back "7 clauses", and in production "Jasper Tey updated 2
     * terms sheets to current doctrine" rendered above a disclosure reading
     * "Show all 5". The 2 counts sheets, the 5 counts acts, and nothing on
     * screen says so; two readers who knew the mechanism both stumbled. The
     * distinct count is the most truthful number and the worst one to
     * display. On the same screen a sentence with NO number over a counted
     * disclosure read perfectly — so the number goes and the plural stays,
     * because "updated terms sheets" is true of two sheets and of forty, and
     * a Polish "klauzule" versus "klauzul" is still decided by how many there
     * really are. Counting acts instead was the other fix, and an author can
     * write it ("5 times" at the END of the clause); a mid-sentence
     * substitution cannot.
     *
     * A null noun falls back to GENERIC rather than declining to render.
     */
    public static function form(string|self|null $noun, int $count): string
    {
        $noun = match (true) {
            $noun instanceof self => $noun,
            is_string($noun) => self::of($noun),
            default => self::of(self::GENERIC),
        };

        return $noun->forCount($count);
    }
}
