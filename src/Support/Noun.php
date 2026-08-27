<?php

namespace Storyfeed\Support;

use Illuminate\Translation\MessageSelector;
use InvalidArgumentException;

/**
 * The plural forms of the thing a role holds — "clause|clauses" — and the
 * count phrase built from them ("7 clauses").
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
 *         'document' => Noun::trans('nouns.document'),
 *     ]);
 *
 * Literal forms go through Laravel's MessageSelector rather than an English
 * `Str::plural()`, so a locale with more than two forms is served by adding
 * segments: Polish `klauzula|klauzule|klauzul` selects correctly at
 * n = 1 / 2 / 5 / 22 (NounTest proves it). Hand-rolled pluralisation could
 * never do that, and would be wrong in English soon enough anyway.
 */
final class Noun
{
    /**
     * The noun used when a type has none registered.
     *
     * A missing noun does NOT skip the rung: "7 items" is bland, but the
     * screen belongs to the reader while the nagging belongs to the
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
                .'forms take more segments, and a translation key goes behind Noun::trans().',
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
        return $this->translated
            ? (string) trans_choice($this->value, $count)
            : (string) (new MessageSelector)->choose($this->value, $count, app()->getLocale());
    }

    /**
     * "7 clauses" — the phrase that replaces an unpinned role token.
     *
     * A null noun falls back to GENERIC rather than declining to render.
     */
    public static function phrase(string|self|null $noun, int $count): string
    {
        $noun = match (true) {
            $noun instanceof self => $noun,
            is_string($noun) => self::of($noun),
            default => self::of(self::GENERIC),
        };

        return number_format($count).' '.$noun->forCount($count);
    }
}
