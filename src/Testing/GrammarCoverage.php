<?php

namespace Storyfeed\Testing;

use PHPUnit\Framework\Assert;
use Storyfeed\Models\Activity;
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

        $pairs = $model::query()
            ->join($groupings, "{$groupings}.activity_id", '=', "{$activities}.id")
            ->where("{$groupings}.winner", true)
            ->whereIn("{$groupings}.bucket", ['actors', 'targets', 'object'])
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
