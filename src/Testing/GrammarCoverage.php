<?php

namespace Storyfeed\Testing;

use PHPUnit\Framework\Assert;
use Storyfeed\Models\Activity;
use Storyfeed\Models\Grouping;
use Storyfeed\StoryfeedManager;

/**
 * Asserts that every activity an application publishes has a headline and an
 * icon authored for it.
 *
 * A missing grammar entry is not an error at runtime — the headline is simply
 * null and the icon falls back to `*.*`. That silence is exactly why this
 * exists: it turns "nobody wrote a headline for delivery.confirm" into a
 * failing test rather than a blank line in production.
 *
 *   Storyfeed::fake();
 *   // exercise the code that publishes
 *   GrammarCoverage::assertCoversRecorded();
 *
 * Prefer the recorded form over a hand-maintained list: it asserts against
 * what the application actually publishes, so a new activity type can't be
 * added without also being authored.
 *
 * WHAT THIS DOES NOT PROVE, and it is less than people assume. Every assertion
 * here answers one question — *does a template EXIST for this pair?* None of
 * them can tell you:
 *
 *  - that the axis ever FIRES. A verb whose activities stopped grouping — a
 *    changed threshold, a role no longer filled, a curator regression — leaves
 *    every one of these assertions green, because the templates are all still
 *    registered.
 *  - that the template which RESOLVES is the one a reader should see. Coverage
 *    is satisfied by a `type.*` or `*.verb` wildcard standing in for the
 *    specific entry you meant to author.
 *  - that the resulting sentence is TRUE of its group. Token safety is checked
 *    at compile time and by doctor, but "at most one plural list per template"
 *    and whether the prose reads well are untoolable by design.
 *
 * A consumer found four of six built-in axes with no payload-level proof at all,
 * while every coverage assertion they owned was green — the axes could have
 * silently stopped being reachable and nothing would have said so. Pair these
 * with assertions over the actual payload: that the group node appears, on the
 * axis you expect, with the headline you meant. This package's own
 * `tests/ReadPath/CurationTest.php` is the worked example, including both
 * directions of the object-axis case (one object groups; several fall back).
 */
class GrammarCoverage
{
    /**
     * Assert coverage for explicit [objectType, verb] pairs.
     *
     * @param  array<int, array{0: string|null, 1: string}>  $pairs
     */
    public static function assertCovers(array $pairs, bool $allowWildcard = false): void
    {
        $storyfeed = app(StoryfeedManager::class);

        $missing = [];

        foreach ($pairs as [$type, $verb]) {
            $label = ($type ?? '*').'.'.$verb;

            if (! self::covered($storyfeed->templateKey($type, $verb), $allowWildcard)) {
                $missing[] = "{$label} (no headline)";
            }

            if (! self::covered($storyfeed->iconKey($type, $verb), $allowWildcard)) {
                $missing[] = "{$label} (no icon)";
            }
        }

        Assert::assertSame(
            [],
            $missing,
            "Storyfeed grammar coverage is incomplete:\n  - ".implode("\n  - ", $missing)
            ."\n\nRegister the missing entries with Storyfeed::grammar() / Storyfeed::icons().",
        );
    }

    /**
     * Assert coverage for everything recorded by the active fake.
     */
    public static function assertCoversRecorded(bool $allowWildcard = false): void
    {
        $storyfeed = app(StoryfeedManager::class);

        Assert::assertInstanceOf(
            StoryfeedFake::class,
            $storyfeed,
            'GrammarCoverage::assertCoversRecorded() requires Storyfeed::fake(). '
            .'Use assertCoversPublished() to check activities in the database instead.',
        );

        $pairs = $storyfeed->recordedPairs();

        Assert::assertNotEmpty(
            $pairs,
            'No activities were published, so grammar coverage proves nothing.',
        );

        self::assertCovers($pairs, $allowWildcard);
    }

    /**
     * Assert coverage for every distinct pair persisted in the feed — useful
     * as an end-of-suite or seeded-database check.
     */
    public static function assertCoversPublished(bool $allowWildcard = false): void
    {
        $model = config('storyfeed.models.activity', Activity::class);

        $pairs = $model::query()
            ->distinct()
            ->get(['object_type as type', 'verb'])
            ->map(fn ($row) => [$row->type, $row->verb])
            ->all();

        Assert::assertNotEmpty(
            $pairs,
            'No activities have been published, so grammar coverage proves nothing.',
        );

        self::assertCovers($pairs, $allowWildcard);
    }

    /**
     * Assert aggregate grammar for every axis actually in use — the
     * (axis, verb) pairs curation has stamped as winners.
     *
     * Fails when nothing is grouped on an aggregate axis, rather than
     * passing over an empty set: a green assertion there proves nothing.
     */
    public static function assertCoversAggregates(bool $allowWildcard = false): void
    {
        $storyfeed = app(StoryfeedManager::class);

        $groupings = config('storyfeed.tables.groupings', 'feed_groupings');
        $activities = config('storyfeed.tables.activities', 'feed_activities');
        $model = config('storyfeed.models.activity', Activity::class);
        $grouping = config('storyfeed.models.grouping', Grouping::class);

        // Fallback axis included, clusters of 2+ only — repeat groups render
        // aggregate headlines like any other axis, and excluding the
        // fallback here once hid a missing repeat.* key through four rounds
        // of audits (curation priority is a different question from whether
        // a headline resolves).
        $clustered = $grouping::query()
            ->select(["{$groupings}.bucket", "{$groupings}.hash"])
            ->where("{$groupings}.winner", true)
            ->whereIn("{$groupings}.bucket", array_keys($storyfeed->registeredAxes()))
            ->groupBy(["{$groupings}.bucket", "{$groupings}.hash"])
            ->havingRaw('count(*) > 1');

        $pairs = $model::query()
            ->join($groupings, "{$groupings}.activity_id", '=', "{$activities}.id")
            ->where("{$groupings}.winner", true)
            ->joinSub($clustered, 'clustered', function ($join) use ($groupings) {
                $join->on('clustered.bucket', '=', "{$groupings}.bucket")
                    ->on('clustered.hash', '=', "{$groupings}.hash");
            })
            ->distinct()
            ->get(["{$groupings}.bucket as axis", "{$activities}.verb"]);

        Assert::assertNotEmpty(
            $pairs,
            'No activities are grouped on an aggregate axis, so aggregate grammar coverage proves nothing.',
        );

        $missing = [];

        foreach ($pairs as $pair) {
            if (! self::covered($storyfeed->aggregateTemplateKey($pair->axis, $pair->verb), $allowWildcard)) {
                $missing[] = "{$pair->axis}.{$pair->verb} (no aggregate headline)";
            }
        }

        Assert::assertSame(
            [],
            $missing,
            "Storyfeed aggregate grammar coverage is incomplete:\n  - ".implode("\n  - ", $missing)
            ."\n\nRegister the missing entries with Storyfeed::aggregateGrammar().",
        );
    }

    /**
     * Assert aggregate grammar for an explicit (axis, verb) matrix — the
     * proactive form. assertCoversAggregates() only sees combinations the
     * test run happened to produce (curation only stamps winners the data
     * contains); this one asserts what COULD occur:
     *
     *   GrammarCoverage::assertCoversAggregateMatrix(
     *       axes: ['actors', 'targets'],
     *       verbs: ['upload', 'comment', 'approve'],
     *   );
     *
     * @param  array<int, string>  $axes
     * @param  array<int, string>  $verbs
     */
    public static function assertCoversAggregateMatrix(array $axes, array $verbs, bool $allowWildcard = false): void
    {
        Assert::assertNotEmpty($axes, 'No axes given, so aggregate matrix coverage proves nothing.');
        Assert::assertNotEmpty($verbs, 'No verbs given, so aggregate matrix coverage proves nothing.');

        $storyfeed = app(StoryfeedManager::class);

        $missing = [];

        foreach ($axes as $axis) {
            foreach ($verbs as $verb) {
                if (! self::covered($storyfeed->aggregateTemplateKey($axis, $verb), $allowWildcard)) {
                    $missing[] = "{$axis}.{$verb} (no aggregate headline)";
                }
            }
        }

        Assert::assertSame(
            [],
            $missing,
            "Storyfeed aggregate grammar coverage is incomplete:\n  - ".implode("\n  - ", $missing)
            ."\n\nRegister the missing entries with Storyfeed::aggregateGrammar().",
        );
    }

    /**
     * Assert aggregate grammar for every (axis, verb) pair the app COULD
     * produce — derived from the axes' compiled recipes and the roles each verb
     * actually fills, rather than reasoned about by hand.
     *
     *   Storyfeed::fake();
     *   // exercise the code that publishes
     *   GrammarCoverage::assertCoversPossibleAggregates();
     *
     * This is the one-line replacement for hand-partitioned matrices. A
     * consumer maintained three `assertCoversAggregateMatrix()` calls split by
     * which verbs each axis can semantically produce, with a comment conceding
     * the reasoning "has already aged once" — and it had: doctor found an
     * `object.join` gap the written analysis had declared impossible.
     *
     * Two honest limits, both stated in the failure message rather than buried
     * here:
     *
     * 1. Role-fill is OBSERVED. A verb only ever recorded without a target
     *    looks like it can never have one, so this is a strictly better
     *    superset than hand-partitioning — not a proof. Keep
     *    assertCoversAggregateMatrix() for asserting ahead of any traffic.
     * 2. Closure-recipe and row-backed axes are skipped, because their
     *    applicability is not derivable. They are NAMED in the message, so a
     *    skipped category can never masquerade as a clean one — a coverage tool
     *    that silently skips is indistinguishable from a healthy system.
     */
    public static function assertCoversPossibleAggregates(bool $allowWildcard = false): void
    {
        $storyfeed = app(StoryfeedManager::class);

        $roleMap = $storyfeed instanceof StoryfeedFake
            ? $storyfeed->recordedRoles()
            : self::recordedRolesFromDatabase();

        Assert::assertNotEmpty(
            $roleMap,
            'No activities were published, so aggregate grammar coverage proves nothing.',
        );

        $pairs = $storyfeed->possibleAggregatePairs($roleMap);

        Assert::assertNotEmpty(
            $pairs,
            'No axis applies to any recorded verb, so aggregate grammar coverage proves nothing. '
            .'(Closure-recipe and row-backed axes are excluded — their applicability is not derivable.)',
        );

        $missing = [];

        foreach ($pairs as [$axis, $verb]) {
            if (! self::covered($storyfeed->aggregateTemplateKey($axis, $verb), $allowWildcard)) {
                $missing[] = "{$axis}.{$verb} (no aggregate headline)";
            }
        }

        $undecidable = array_keys(array_filter(
            $storyfeed->registeredAxes(),
            fn ($axis) => $axis->requiredRoles() === null,
        ));

        $caveat = "\n\nDerived from the roles each verb was observed filling, so it covers what this run "
            .'exercised — not every shape the verb can take.'
            .($undecidable === []
                ? ''
                : ' NOT checked here (applicability is not derivable): '.implode(', ', $undecidable)
                  .' — assert those with assertCoversAggregateMatrix().');

        Assert::assertSame(
            [],
            $missing,
            "Storyfeed aggregate grammar coverage is incomplete:\n  - ".implode("\n  - ", $missing)
            ."\n\nRegister the missing entries with Storyfeed::aggregateGrammar(), or run "
            .'`php artisan storyfeed:doctor --stubs` to print them.'.$caveat,
        );
    }

    /**
     * @return array<string, list<string>>
     */
    protected static function recordedRolesFromDatabase(): array
    {
        $model = config('storyfeed.models.activity', Activity::class);

        $map = [];

        $rows = $model::query()
            ->distinct()
            ->toBase()
            ->get(['verb', 'actor_type', 'object_type', 'target_type', 'context_type']);

        foreach ($rows as $row) {
            $map[$row->verb] ??= [];

            foreach (['actor', 'object', 'target', 'context'] as $role) {
                if ($row->{"{$role}_type"} !== null) {
                    $map[$row->verb][$role] = true;
                }
            }
        }

        return array_map(
            fn (array $roles) => array_values(array_filter(array_keys($roles), 'is_string')),
            $map,
        );
    }

    /**
     * A `*.*` catch-all resolves for everything, which would make coverage
     * vacuous — so it only counts when explicitly allowed.
     */
    protected static function covered(?string $key, bool $allowWildcard): bool
    {
        if ($key === null) {
            return false;
        }

        return $allowWildcard || $key !== '*.*';
    }
}
