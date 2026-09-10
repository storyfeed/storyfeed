<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\Diagnostics\Fix;
use Storyfeed\Grouping\Axis;
use Storyfeed\StoryfeedManager;

/**
 * An axis recipe that omits `v` opts out of aggregate grammar, and nothing
 * says so at the point where it is authored.
 *
 * Grouping does not stop two verbs sharing a group — GRAMMAR does. Aggregate
 * templates are keyed `axis.verb`, and `NodePresenter` resolves them with the
 * HEAD MEMBER's verb, so on an axis that does not pin the verb the sentence a
 * group renders is chosen by whichever member happens to sort first and then
 * asserted over members that did something else. A consumer keyed an axis
 * `'oa!:oid!:d'` to put "everything about this photo today" in one group, got
 * an upload and a moderation approval in it, and met the consequence much
 * later as "why does my group render as a bare count".
 *
 * WHY THIS ONE IS DIFFERENT FROM EVERY OTHER COVERAGE CHECK. It is answered
 * from the REGISTRY ALONE — at boot, with no traffic, before a single group
 * has formed. `AggregateCoverage` can only speak about pairs that clustered on
 * rows that exist; this speaks about an axis the moment it is registered, which
 * is also the moment the author is still holding the recipe in their head.
 *
 * WARNING, NOT ERROR (2026-09-10). By W121's rule a finding is an Error when
 * it describes something wrong TODAY on a surface that EXISTS, and neither
 * premise is available here: this check deliberately looks at no rows and no
 * feeds, so it cannot know that a mixed-verb group has formed or that anything
 * reads the axis. The nearest neighbour settles it — `tokens.unpinned` is a
 * Warning for a template naming a token the axis does not pin, and a template
 * KEYED on a verb the axis does not pin is the same defect one level up. When
 * rows do prove it, `aggregates.missing` says so at Error, as it already did.
 *
 * WHAT IT DOES NOT LOOK AT, on purpose. Closure-recipe and row-backed axes are
 * skipped: `pins()` declares whole tokens rather than the compiled mask, so an
 * author who simply never considered `:verb` is indistinguishable from one who
 * ruled it out, and this check would rather miss a gap than confidently deny
 * one (Axis::requiredRoles()). Wildcard-AXIS keys (`*.upload`) are skipped too
 * — they serve the verb-pinning axes legitimately, and there is no edit to
 * suggest that would not break those.
 */
class VerblessAxis extends Check
{
    public function name(): string
    {
        return 'axes';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        $grammar = $storyfeed->registeredAggregateGrammar();

        foreach ($storyfeed->registeredAxes() as $name => $axis) {
            if ($axis->pinsVerb() !== false) {
                continue; // pins the verb, or is not derivable — see the docblock
            }

            // The wildcard entries are the only keys that can be true of a
            // group whose members did different things.
            $agnostic = array_key_exists("{$name}.*", $grammar) ? "{$name}.*"
                : (array_key_exists('*.*', $grammar) ? '*.*' : null);

            if ($agnostic === null) {
                yield $this->unsayable($storyfeed, $axis);
            }

            foreach ($this->perVerbKeys($grammar, $name) as $key) {
                yield $this->perVerb($key, $axis, $agnostic);
            }
        }
    }

    /**
     * No key can serve this axis, so its group nodes have no sentence of their
     * own — and the fallback that catches them is blind here in a way it is
     * nowhere else.
     */
    protected function unsayable(StoryfeedManager $storyfeed, Axis $axis): Finding
    {
        return Finding::warning(
            'axes.verbless_no_grammar',
            "Group nodes on `{$axis->name}` have no aggregate sentence that could be true of them: the axis "
            .'recipe omits `v`, so its groups may span several verbs, and no verb-agnostic key '
            ."(`{$axis->name}.*` or `*.*`) is registered. They fall to the head member's SINGULAR headline "
            .'wherever the token check admits it — and that check cannot see the verb, which lives in the '
            .'template\'s prose rather than in a token, so "Sally uploaded a photo" is admitted over a group '
            .'that also contains an approval — and to a bare count otherwise. Author one sentence true of '
            .'every verb on this axis, or add `v` to the recipe and key per verb.',
            ['axis' => $axis->name],
            // A per-verb stub is the wrong shape for this axis, whatever the
            // coverage check offers once rows exist; the key is the wildcard.
            Fix::make(
                'aggregateGrammar',
                "{$axis->name}.*",
                $storyfeed->aggregateTokens($axis->name) ?? [],
            ),
        );
    }

    /**
     * A key that names a verb the axis cannot promise. No Fix: the two ways
     * out are a recipe change — which rewrites every hash on the axis — and a
     * rewritten sentence, which only taste validates. Printing either as a
     * paste-ready stub would be a guess wearing a suggestion's clothes.
     */
    protected function perVerb(string $key, Axis $axis, ?string $agnostic): Finding
    {
        return Finding::warning(
            'axes.verbless_per_verb_grammar',
            "Aggregate template `{$key}` names a verb that `{$axis->name}` does not pin — the recipe omits `v`, so a "
            .'group on this axis may span several verbs and the template is resolved from whichever member sorts '
            .'first. This sentence renders over members that did something else'
            .($agnostic === null ? '' : ", or `{$agnostic}` renders instead, depending on the head")
            .'. Add `v` to the recipe if the key is meant literally; otherwise say it once, verb-agnostically.',
            ['key' => $key, 'axis' => $axis->name, 'verb' => explode('.', $key, 2)[1]],
        );
    }

    /**
     * @param  array<string, mixed>  $grammar
     * @return list<string>
     */
    protected function perVerbKeys(array $grammar, string $axis): array
    {
        $keys = [];

        foreach (array_keys($grammar) as $key) {
            [$on, $verb] = array_pad(explode('.', (string) $key, 2), 2, '*');

            if ($on === $axis && $verb !== '*') {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }
}
