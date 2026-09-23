<?php

namespace Storyfeed\Support;

use Illuminate\Support\Str;

/**
 * Reads a Story class name into an object and a predicate, and finds the
 * declared verb the predicate spells — AT GENERATOR TIME ONLY.
 *
 * This is where all the inference in the package lives, and it lives here on
 * purpose. A Story registers its own verb, so a wrongly-inferred verb at boot
 * would self-register and sail straight past `verbs.strict`, REMOVING the typo
 * safety net that exists today. Run once by `make:story`, the result is printed
 * in the binding line and never consulted again.
 *
 * THE NAMING CONVENTION: `{Object}Was{Verbed}` — `DocumentWasUploaded`,
 * `ProjectWasArchived`, `PurchaseOrderWasCreated`.
 *
 * The `Was` infix is not decoration. It is the DELIMITER, and its absence is
 * exactly what killed an earlier prototype: `CreatePurchaseOrder` cannot be
 * split (is the object `PurchaseOrder`, or the verb `CreatePurchase`?), which is
 * why "never infer the object" got written down as settled. `PurchaseOrderWasCreated`
 * splits unambiguously, so the multi-word objects that broke token-guessing are
 * exactly the case this handles. It is also distinctive: no Laravel convention
 * uses `Was`, so a Story is never mistaken for an event, job or action.
 *
 * THE VERB RULE: a declared verb matches when the WHOLE predicate — everything
 * after `Was` — is that verb, or one of its past-tense spellings. The spellings
 * are generated FORWARD from the declared verb, never stripped back from the
 * name. Stripping `-ed` is undecidable (`uploaded → upload`, `completed →
 * complete` are the same shape) and once printed `'complet'`; conjugating is
 * not, and where it is unsure it generates every regular spelling, because a
 * spelling nobody types can never match. The result is always a verb the app
 * declared, or nothing.
 *
 * Only the predicate is searched, not the whole name: objects are nouns that
 * are often verbs too, so `CommentWasPosted` would otherwise match both
 * `comment` and `post`.
 */
class StoryName
{
    /**
     * Irregular past participles. Deliberately short — this is a convenience
     * for the common case, not a lemmatizer, and anything it cannot resolve is
     * reported rather than guessed at silently.
     *
     * @var array<string, string>
     */
    protected const IRREGULAR = [
        'sent' => 'send',
        'built' => 'build',
        'made' => 'make',
        'left' => 'leave',
        'read' => 'read',
        'written' => 'write',
        'paid' => 'pay',
        'held' => 'hold',
        'kept' => 'keep',
        'lost' => 'lose',
        'won' => 'win',
        'set' => 'set',
        'put' => 'put',
        'sold' => 'sell',
        'told' => 'tell',
        'found' => 'find',
        'begun' => 'begin',
        'chosen' => 'choose',
        'given' => 'give',
        'taken' => 'take',
        'seen' => 'see',
        'done' => 'do',
    ];

    /**
     * The object and the predicate either side of the `Was` delimiter, or
     * nulls when the name does not follow the convention.
     *
     * `Was` counts only as a whole StudlyCase word, so `WasteWasCollected`
     * splits after `Waste`.
     *
     * @return array{object: string|null, predicate: string|null}
     */
    public static function parse(string $class): array
    {
        $base = class_basename($class);

        // Tolerate a `Story` suffix even though the convention omits it: someone
        // will type it, and silently producing a verb of `story` would be worse
        // than accepting it.
        $base = Str::endsWith($base, 'Story') ? Str::beforeLast($base, 'Story') : $base;

        if (! preg_match('/^([A-Z][A-Za-z0-9]*?)Was([A-Z][A-Za-z0-9]*)$/', $base, $match)) {
            return ['object' => null, 'predicate' => null];
        }

        return ['object' => $match[1], 'predicate' => $match[2]];
    }

    /**
     * Every declared verb the class name's predicate spells: none, one, or —
     * when two declared verbs share a spelling — more than one, which the
     * caller reports rather than picks from.
     *
     * @param  array<int, string>  $knownVerbs  the app's vocabulary, the only authority on the verb
     * @return list<string>
     */
    public static function verbsIn(string $class, array $knownVerbs): array
    {
        $predicate = self::parse($class)['predicate'];

        if ($predicate === null) {
            return [];
        }

        return array_values(array_filter(
            $knownVerbs,
            fn (string $verb) => in_array(Str::lower($predicate), self::spellings($verb), true),
        ));
    }

    /**
     * How a verb can appear as a class name's predicate, lower-cased: the verb
     * itself, and its past tense conjugated one word at a time (`check_in` →
     * `checkedin`, `tentativeAccept` → `tentativeaccepted`).
     *
     * Generous on purpose. Doubling is undecidable (`shipped`, but `visited`),
     * so both are generated; the one nobody types never matches anything.
     *
     * @return list<string>
     */
    public static function spellings(string $verb): array
    {
        $words = explode(' ', Str::snake(Str::studly($verb), ' '));

        $spellings = [implode('', $words)];

        foreach ($words as $i => $word) {
            foreach (self::pastTenses($word) as $past) {
                $spellings[] = implode('', array_replace($words, [$i => $past]));
            }
        }

        return array_values(array_unique($spellings));
    }

    /** @return list<string> every regular past tense of one word, plus its irregular one */
    protected static function pastTenses(string $word): array
    {
        $last = substr($word, -1);

        return array_values(array_filter([
            self::participle($word),
            $word.'ed',                                              // upload → uploaded, play → played
            Str::endsWith($word, 'e') ? $word.'d' : null,            // complete → completed
            Str::endsWith($word, 'y') ? substr($word, 0, -1).'ied' : null, // copy → copied
            Str::endsWith($word, 'c') ? $word.'ked' : null,          // panic → panicked
            ctype_alpha($last) && ! str_contains('aeiouwxy', $last) ? $word.$last.'ed' : null, // ship → shipped
        ]));
    }

    /**
     * The past participle of an imperative — for building a class name FROM a
     * recorded verb (`--from-doctor`).
     *
     * Only the easy direction is attempted, because only the easy direction is
     * decidable: appending is regular where stripping is not.
     */
    public static function participle(string $verb): string
    {
        $verb = Str::lower($verb);

        if ($irregular = array_search($verb, self::IRREGULAR, true)) {
            return $irregular;
        }

        return match (true) {
            Str::endsWith($verb, 'ed') => $verb,
            Str::endsWith($verb, 'e') => $verb.'d',          // archive → archived
            Str::endsWith($verb, 'y') && ! self::vowel(substr($verb, -2, 1)) => substr($verb, 0, -1).'ied', // apply → applied
            default => $verb.'ed',                            // upload → uploaded, play → played
        };
    }

    /**
     * The participle where appending is CERTAIN, else null — for printing a
     * sentence the developer may paste as it stands (`storyfeed:doctor --stubs`).
     *
     * Null for anything but one plain word of three letters or more; for a
     * word ending consonant-vowel-consonant, where doubling turns on stress no
     * spelling reveals (`ship → shipped` but `visit → visited`); and for one
     * already ending `-ed`, which `participle()` keeps as it stands (`embed`).
     * Irregulars are known.
     */
    public static function certainParticiple(string $verb): ?string
    {
        $verb = Str::lower($verb);

        if (array_search($verb, self::IRREGULAR, true) !== false) {
            return self::participle($verb);
        }

        if (! ctype_alpha($verb) || strlen($verb) < 3 || Str::endsWith($verb, 'ed')) {
            return null;
        }

        $tail = substr($verb, -3);
        $doubling = ! self::vowel($tail[0]) && self::vowel($tail[1])
            && ! self::vowel($tail[2]) && ! str_contains('wxy', $tail[2]);

        return $doubling ? null : self::participle($verb);
    }

    protected static function vowel(string $letter): bool
    {
        return str_contains('aeiou', $letter);
    }
}
