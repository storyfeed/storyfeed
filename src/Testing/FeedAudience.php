<?php

namespace Storyfeed\Testing;

use BackedEnum;
use PHPUnit\Framework\Assert;
use Storyfeed\Contracts\FeedVerb;
use Storyfeed\FeedDefinition;
use Storyfeed\Models\Activity;
use Storyfeed\StoryfeedManager;
use Storyfeed\Support\VerbFilter;

/**
 * Assertions that pin a named feed's AUDIENCE — "this feed cannot render this
 * verb" — in the app's own suite.
 *
 *   FeedAudience::assertRefuses('customer', 'order.margin_note');
 *   FeedAudience::assertAllows('customer', ['order.placed', OrderVerb::Paid]);
 *   FeedAudience::assertAllowsOnly('customer', ['order.*']);
 *
 * The distinction from the `feeds` doctor check (Diagnostics\Checks\FeedCoverage):
 * that one asks "is every verb DECIDED — named by SOMEBODY's allowlist or
 * denylist", which is a vocabulary-hygiene question and is answered across the
 * whole registry. This asks "would THIS feed show THIS verb", which is the
 * question an app has when it adopted named feeds precisely because it feared
 * leaking an internal verb to customers. Doctor can be entirely green while the
 * customer feed shows `order.margin_note`, because being denied SOMEWHERE
 * counts as decided.
 *
 * Registration is not the assertion. An app that trusts `Storyfeed::feeds()`
 * to stay correct is trusting a provider nobody re-reads, and the failure is
 * silent in exactly the direction that matters — the verb renders. Pin it.
 *
 * Works identically under `Storyfeed::fake()` and against real tables, because
 * these read the feed's DECLARATION rather than rows: only()/except() and a
 * single verb() are inspected the same way `storyfeed:doctor` inspects them,
 * including for a subject feed whose constructor tooling cannot satisfy.
 *
 * WHAT THIS DOES NOT PROVE, in the order people assume it:
 *
 *  - **Row-level visibility.** A verb allowlist is about which verbs a surface
 *    is ABOUT. That the customer feed shows only THIS customer's orders is
 *    scope — involving()/context()/query() — and no assertion here looks at it.
 *    Audience is not authorization; docs/feeds.md is emphatic about this and so
 *    is this class.
 *  - **Narrowing done inside `query()`.** A closure that excludes a verb is
 *    real narrowing that nothing can read back, so assertRefuses() will fail on
 *    a feed that does in fact refuse. That is the safe direction, and the
 *    failure message says so. The unsafe direction is assertAllows(): it can
 *    report a verb as shown when a query() callback removes it. If your feed
 *    filters verbs in query(), assert over the payload instead — publish one and
 *    look for it.
 *  - **That the verb exists.** Verbs are free-form strings in storage, so a
 *    typo'd verb in assertRefuses() passes vacuously — it refuses everything
 *    it has never heard of. assertAllowsOnly() is the form that catches this,
 *    because it works over the app's whole vocabulary rather than a list the
 *    same person wrote twice.
 */
class FeedAudience
{
    /**
     * Assert the feed would show every one of these verbs.
     *
     * @param  array<int, string|FeedVerb|BackedEnum>|string|FeedVerb|BackedEnum  $verbs
     */
    public static function assertAllows(string $feed, array|string|FeedVerb|BackedEnum $verbs): void
    {
        $definition = self::definition($feed);
        $builder = $definition->inspect();

        $refused = array_values(array_filter(
            self::normalize($verbs),
            fn (string $verb) => ! $builder->admits($verb),
        ));

        Assert::assertSame(
            [],
            $refused,
            "Feed `{$feed}` refuses verbs it was expected to show:\n  - ".implode("\n  - ", $refused)
            ."\n\nDeclared in {$definition->source}. Its only()/except() patterns: "
            .self::describe($builder->verbFilter()).'.',
        );
    }

    /**
     * Assert the feed would show none of these verbs — the leak assertion.
     *
     * @param  array<int, string|FeedVerb|BackedEnum>|string|FeedVerb|BackedEnum  $verbs
     */
    public static function assertRefuses(string $feed, array|string|FeedVerb|BackedEnum $verbs): void
    {
        $definition = self::definition($feed);
        $builder = $definition->inspect();

        $leaked = array_values(array_filter(
            self::normalize($verbs),
            fn (string $verb) => $builder->admits($verb),
        ));

        // An unrestricted feed shows everything, so the failure is real — but
        // "customer allows order.margin_note" without saying WHY reads as a
        // pattern bug, and sends the reader to the allowlist they never wrote.
        $why = $builder->isVerbRestricted()
            ? "Its only()/except() patterns: {$definition->source} — ".self::describe($builder->verbFilter()).'.'
            : "Feed `{$feed}` declares no only()/except() at all, so it shows every verb. "
                ."Declared in {$definition->source}.";

        Assert::assertSame(
            [],
            $leaked,
            "Feed `{$feed}` would show verbs it was expected to refuse:\n  - ".implode("\n  - ", $leaked)
            ."\n\n{$why}\n"
            .'(This reads the feed\'s declaration. Verbs excluded inside a query() callback are invisible '
            .'here — assert those over the payload.)',
        );
    }

    /**
     * Assert the feed shows these verbs and NOTHING ELSE the app knows about.
     *
     * The form that catches the verb nobody thought about: the universe is the
     * app's declared vocabulary UNION everything actually recorded — the same
     * union the `feeds` doctor check uses, and for the same reason. The leak
     * this exists for is a verb somebody added six months from now, so a
     * declaration-only universe misses exactly the case.
     *
     * Under Storyfeed::fake() the recorded half comes from the fake; otherwise
     * from the activities table. Either way it refuses to run on no evidence:
     * an empty universe would make this pass while knowing nothing.
     *
     * @param  array<int, string|FeedVerb|BackedEnum>|string|FeedVerb|BackedEnum  $verbs
     */
    public static function assertAllowsOnly(string $feed, array|string|FeedVerb|BackedEnum $verbs): void
    {
        $definition = self::definition($feed);
        $builder = $definition->inspect();
        $expected = self::normalize($verbs);

        $universe = self::vocabulary();

        Assert::assertNotEmpty(
            $universe,
            "No verbs are declared or recorded, so pinning feed `{$feed}`'s allowlist proves nothing. "
            .'Declare a vocabulary with Storyfeed::verbs([...]), or exercise the code that publishes first.',
        );

        $unexpected = [];

        foreach ($universe as $verb) {
            if (! $builder->admits($verb)) {
                continue;
            }

            foreach ($expected as $pattern) {
                if (VerbFilter::matches($pattern, $verb)) {
                    continue 2;
                }
            }

            $unexpected[] = $verb;
        }

        Assert::assertSame(
            [],
            $unexpected,
            "Feed `{$feed}` shows verbs outside the list it was pinned to:\n  - ".implode("\n  - ", $unexpected)
            ."\n\nDeclared in {$definition->source}. Either add the verb to this assertion — deliberately, "
            .'because this audience should see it — or name it in the feed\'s except() list.'
            ."\n(Checked against every verb declared with Storyfeed::verbs() plus every verb recorded, "
            .'so a verb neither declared nor published yet is not covered.)',
        );

        // The other direction, so a pin cannot rot into a list of verbs the
        // feed stopped showing: everything named must actually be admitted.
        self::assertAllows($feed, $expected);
    }

    private static function definition(string $feed): FeedDefinition
    {
        return app(StoryfeedManager::class)->feedDefinition($feed);
    }

    /**
     * @param  array<int, string|FeedVerb|BackedEnum>|string|FeedVerb|BackedEnum  $verbs
     * @return list<string>
     */
    private static function normalize(array|string|FeedVerb|BackedEnum $verbs): array
    {
        $verbs = is_array($verbs) ? $verbs : [$verbs];

        Assert::assertNotEmpty($verbs, 'No verbs given, so the assertion proves nothing.');

        return array_values(array_map(VerbFilter::verbString(...), $verbs));
    }

    /**
     * Declared vocabulary UNION recorded verbs, as FeedCoverage computes it.
     *
     * @return list<string>
     */
    private static function vocabulary(): array
    {
        $storyfeed = app(StoryfeedManager::class);

        $declared = array_filter(
            array_keys($storyfeed->registeredVerbs()),
            fn (string $verb) => $storyfeed->declaredVerb($verb),
        );

        $recorded = $storyfeed instanceof StoryfeedFake
            ? array_map(fn (array $pair) => $pair[1], $storyfeed->recordedPairs())
            : self::recordedFromDatabase();

        $vocabulary = array_values(array_unique([...$declared, ...$recorded]));

        sort($vocabulary);

        return $vocabulary;
    }

    /** @return list<string> */
    private static function recordedFromDatabase(): array
    {
        $model = config('storyfeed.models.activity', Activity::class);

        return array_map(strval(...), $model::query()->distinct()->pluck('verb')->all());
    }

    private static function describe(VerbFilter $filter): string
    {
        return $filter->isEmpty() ? 'none' : implode(', ', $filter->patterns());
    }
}
