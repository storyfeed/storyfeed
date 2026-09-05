<?php

namespace Storyfeed\Diagnostics\Checks;

use Storyfeed\Diagnostics\Finding;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\VerbFilter;
use Throwable;

/**
 * Every verb is DECIDED — named by the allowlist or the denylist of at least
 * one restricted feed (`Storyfeed::feeds([...])`).
 *
 * This check is the whole reason the allowlist seam lives in the package rather
 * than in an application's own service provider. An app-side verb→audience map
 * works perfectly well and is invisible to tooling; a registry one lets a verb
 * recorded carelessly six months from now fail CI instead of leaking. That is
 * the guardrail-fires-for-someone-who-did-not-write-it property, and it is
 * unreachable from app-side config.
 *
 * WHY "DECIDED" AND NOT "ALLOWED SOMEWHERE". The obvious rule — every verb
 * appears in at least one named feed — is trivially satisfied the moment an app
 * declares an unfiltered `'admin' => fn ($feed) => $feed` audience, which is the
 * first thing every app does. It would be green on day one and green on the day
 * someone adds `order.margin_note`: a check that reads as coverage while
 * asserting nothing, which is worse than no check. So OPEN feeds (no
 * only()/except() at all) contribute nothing to classification, and being
 * DENIED counts as classified — somebody looked at the verb and decided.
 *
 * DECLARED UNRESTRICTED IS NOT OPEN (2026-09-05). A feed may say
 * `->unrestricted()`: the audience decision was "everyone", and an operations
 * portal whose world feed quietly dropped a verb would be lying about being one.
 * That feed was carrying eleven permanent "nobody decided" warnings for a
 * decision that WAS made. So a verb classified by no restricted feed is
 * reported as `feeds.unrestricted`, Info, when some feed declared itself the
 * world — instead of `feeds.unclassified`, Warning. Still on every run, so the
 * twelfth verb still surfaces and is still seen; it stops failing CI and stops
 * reading as an open problem. It does NOT count as decided, and that is the
 * whole design: the declaration would auto-decide every verb that does not
 * exist yet, and the check's value is firing for the person who did not write
 * it. Severity changes; silence does not. Omitting an allowlist is still the
 * Warning, because forgetting is not the same act as declaring. The precedent
 * is `aggregates.latent`: reported, no Fix, Info not Warning.
 *
 * The universe is the declared vocabulary UNION the verbs actually recorded.
 * The union is not belt-and-braces: the leak this exists for is a verb nobody
 * ever declared, so a declaration-only universe misses exactly the case.
 *
 * A single `verb()` counts as a restriction, and names the verb it allows. It
 * narrows a feed exactly as `only([...])` does, and reading only the allowlist
 * once made a single-verb feed look wide open to this check — classifying
 * nothing, and hiding a typo'd verb the same way a typo'd allowlist entry hides
 * one.
 *
 * Silent when unused. An app with no registered feeds gets no findings at all,
 * not even an Info suggesting it declare some.
 *
 * EVERY FINDING THAT NAMES A FEED CARRIES ITS SOURCE — `app/Feeds/CustomerFeed.php:14`,
 * or the provider line that opened a closure. Both forms reflect, so this is not
 * a class-only privilege; what the class form adds is a STABLE identity, since
 * a file does not move when someone reorders the array in a provider.
 */
class FeedCoverage extends Check
{
    public function name(): string
    {
        return 'feeds';
    }

    public function run(StoryfeedManager $storyfeed): iterable
    {
        $feeds = $storyfeed->registeredFeeds();

        if ($feeds === []) {
            return;
        }

        $filters = [];
        $sources = [];
        $unrestricted = [];

        foreach ($feeds as $name => $definition) {
            $sources[$name] = $definition->source;

            // Each feed gets its own try/catch rather than relying on Doctor's
            // run-wide one: a single app closure that throws would otherwise
            // cost every other feed's findings, and this check is most valuable
            // precisely when part of the config is broken.
            //
            // inspect() rather than build(): a Feed class takes its subject as
            // a constructor argument, so doctor cannot construct one — but it
            // can still read what the class DECLARED, which is the whole reason
            // define() and scope() are separate hooks.
            try {
                $builder = $definition->inspect();
                $filters[$name] = $builder->declaredVerbFilter();

                // Only an OPEN feed's word counts. A feed cannot both filter
                // and be the world, and the builder already refuses the
                // declaration that says so.
                if ($filters[$name]->isEmpty() && $builder->declaredUnrestricted()) {
                    $unrestricted[] = $name;
                }
            } catch (Throwable $e) {
                yield Finding::warning(
                    'feeds.preset_failed',
                    "Feed `{$name}` threw ".$e::class.' while being inspected, so its verbs could not '
                    ."be classified — every verb it was meant to decide is unchecked. Declared in {$definition->source}.",
                    ['feed' => $name, 'exception' => $e::class, 'source' => $definition->source],
                );
            }
        }

        // Restricted feeds are the only ones that classify anything. An
        // unfiltered feed says nothing about who may see what.
        $restricted = array_filter($filters, fn (VerbFilter $filter) => ! $filter->isEmpty());

        if ($restricted === []) {
            yield Finding::info(
                'feeds.none_restricted',
                'Feeds are registered but none declares only() or except(), so no verb is scoped to an '
                .'audience — the registry is documentation, not a guardrail.',
                ['feeds' => count($filters)],
            );

            return;
        }

        $declared = array_keys($storyfeed->registeredVerbs());
        $recorded = $this->hasTable('activities')
            ? $this->activities()->distinct()->pluck('verb')->all()
            : [];

        // Only the app's OWN declarations, for the same reason VerbDrift gives:
        // the 29 shipped defaults are not this app's vocabulary, and demanding
        // an audience decision on `tentativeReject` would bury the real signal.
        $vocabulary = array_values(array_unique(array_merge(
            array_filter($declared, fn (string $verb) => $storyfeed->declaredVerb($verb)),
            array_map(strval(...), $recorded),
        )));

        sort($vocabulary);

        foreach ($vocabulary as $verb) {
            $classified = false;

            foreach ($restricted as $filter) {
                if ($filter->mentions($verb)) {
                    $classified = true;

                    break;
                }
            }

            if ($classified) {
                continue;
            }

            if ($unrestricted !== []) {
                $world = implode('`, `', $unrestricted);

                yield Finding::info(
                    'feeds.unrestricted',
                    "Verb `{$verb}` is named by no restricted feed, and `{$world}` declares itself unrestricted, "
                    .'so it is world-visible by declaration rather than by omission. Reported anyway: the feed '
                    .'that carries everything carries this too, and a verb recorded next year lands here without '
                    .'anyone looking. Name it in the allowlist or the denylist of a restricted feed if not '
                    .'everyone should see it.',
                    ['verb' => $verb, 'feeds' => implode(',', $unrestricted)],
                );

                continue;
            }

            yield Finding::warning(
                'feeds.unclassified',
                "Verb `{$verb}` is named by no restricted feed, so nobody decided who may see it: it is "
                .'absent from every only() feed and present in every except() feed, silently either way. '
                .'Name it in the allowlist or the denylist of a feed in Storyfeed::feeds([...]).',
                ['verb' => $verb],
            );
        }

        // A typo in an allowlist is the quiet failure on the other side: the
        // real verb is not named, so it vanishes from the feed that was
        // supposed to carry it. Wildcards are never reported — a pattern
        // matching nothing yet is authoring ahead of traffic.
        $known = array_merge($vocabulary, $declared);

        // Whether the app has opted into a vocabulary at all — same test
        // VerbDrift uses, and for the same reason: before there is a declared
        // vocabulary to deviate from, an unrecognised verb is not evidence.
        $opted = array_filter($declared, fn (string $verb) => $storyfeed->declaredVerb($verb)) !== [];

        foreach ($restricted as $name => $filter) {
            foreach ($filter->literals() as $literal) {
                if (in_array($literal, $known, true)) {
                    continue;
                }

                $source = $sources[$name];
                $message = "Feed `{$name}` names verb `{$literal}`, which is neither declared nor recorded";

                yield $opted
                    ? Finding::warning(
                        'feeds.unknown_verb',
                        $message.' — likely a typo, and a typo in an allowlist silently drops the real '
                        ."verb from that feed. Declared in {$source}.",
                        ['feed' => $name, 'verb' => $literal, 'source' => $source],
                    )
                    : Finding::info(
                        'feeds.unknown_verb',
                        $message." — fine if you are authoring ahead of traffic. Declared in {$source}.",
                        ['feed' => $name, 'verb' => $literal, 'source' => $source],
                    );
            }
        }
    }
}
