<?php

namespace Storyfeed\Support;

use Illuminate\Support\Str;

/**
 * Reads a Story class name into an object type and a verb — AT GENERATOR TIME
 * ONLY.
 *
 * This is where all the inference in the package lives, and it lives here on
 * purpose. A Story registers its own verb, so a wrongly-inferred verb at boot
 * would self-register and sail straight past `verbs.strict`, REMOVING the typo
 * safety net that exists today. Run once by `make:story`, the same guess gets
 * written into the generated file as a literal — visible in the diff, editable,
 * and never consulted again. That makes it safe to be aggressive here.
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
     * @param  array<int, string>  $knownVerbs  the app's vocabulary, used to disambiguate
     * @return array{object: string|null, verb: string|null, confident: bool}
     */
    public static function parse(string $class, array $knownVerbs = []): array
    {
        $base = class_basename($class);

        // Tolerate a `Story` suffix even though the convention omits it: someone
        // will type it, and silently producing a verb of `story` would be worse
        // than accepting it.
        $base = Str::endsWith($base, 'Story') ? Str::beforeLast($base, 'Story') : $base;

        if (! Str::contains($base, 'Was')) {
            return ['object' => null, 'verb' => null, 'confident' => false];
        }

        $object = Str::before($base, 'Was');
        $participle = Str::after($base, 'Was');

        if ($object === '' || $participle === '') {
            return ['object' => null, 'verb' => null, 'confident' => false];
        }

        $candidates = self::candidates($participle);

        // Prefer a candidate the app has already declared. This is the whole
        // reason to bother with candidates rather than one rule: the app's own
        // vocabulary is a better authority than any suffix heuristic.
        $known = array_values(array_intersect($candidates, $knownVerbs));

        return [
            'object' => $object,
            'verb' => $known[0] ?? $candidates[0] ?? null,
            'confident' => count($known) === 1,
        ];
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
            Str::endsWith($verb, 'y') => Str::beforeLast($verb, 'y').'ied',  // apply → applied
            default => $verb.'ed',                            // upload → uploaded
        };
    }

    /**
     * Plausible imperatives for a past participle, best first.
     *
     * DELIBERATELY NOT CLEVER. `uploaded → upload` and `frobnicated →
     * frobnicate` are structurally identical (vowel, consonant, "ed"), so no
     * suffix rule can separate them — it needs a dictionary. Rather than pick a
     * rule that is wrong half the time, this returns every plausible form and
     * lets two better authorities decide: the app's declared vocabulary, and
     * failing that, the developer reading the warning.
     *
     * That is only acceptable because this runs at GENERATOR time. At boot the
     * same ambiguity would silently register a nonsense verb.
     *
     * @return array<int, string>
     */
    public static function candidates(string $participle): array
    {
        $word = Str::lower($participle);

        if (isset(self::IRREGULAR[$word])) {
            return [self::IRREGULAR[$word]];
        }

        $candidates = [];

        if (Str::endsWith($word, 'ied')) {
            $candidates[] = Str::beforeLast($word, 'ied').'y';   // applied → apply
        }

        if (Str::endsWith($word, 'ed')) {
            $stem = Str::beforeLast($word, 'ed');

            // submitted → submit: a doubled final consonant is an artefact of
            // the suffix, not part of the word.
            if (strlen($stem) > 2 && $stem[-1] === $stem[-2] && ! in_array($stem[-1], ['s', 'l'], true)) {
                $candidates[] = substr($stem, 0, -1);
            }

            $candidates[] = $stem;                                // uploaded → upload
            $candidates[] = $stem.'e';                            // creat + e → create
        }

        $candidates[] = $word;

        return array_values(array_unique(array_filter($candidates)));
    }
}
