<?php

use Illuminate\Support\Collection;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Models\Delivery;
use Workbench\App\Stories\DeliveryWasConfirmed;

/**
 * FeedCoverage — the reason the allowlist seam lives in the package rather than
 * in an app's own service provider. An app-side verb→audience map works and is
 * invisible to tooling; this makes a verb nobody assigned to an audience a CI
 * failure instead of a leak six months from now.
 *
 * The load-bearing test is the fourth one: an unfiltered `admin` preset must NOT
 * count as deciding anything. The obvious rule ("every verb appears in at least
 * one named feed") is green forever the moment an app declares one, which is a
 * check that reads as coverage while asserting nothing.
 */
function feedFindings(): Collection
{
    return collect(Storyfeed::doctor(['feeds'])->toArray()['findings'] ?? [])
        ->when(true, fn ($c) => $c);
}

function feedCodes(): array
{
    return feedFindings()->pluck('code')->all();
}

it('says nothing at all when no feeds are registered', function () {
    // The constraint the lane is built under: an app that never calls feeds()
    // sees no difference, including in doctor.
    Storyfeed::verbs(ActivityVerb::class);
    Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    expect(feedCodes())->toBe([]);
});

it('reports a declared verb no restricted feed names', function () {
    Storyfeed::verbs(ActivityVerb::class);

    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed->only(['confirm', 'upload']),
    ]);

    $unclassified = feedFindings()->where('code', 'feeds.unclassified')->pluck('subject.verb');

    expect($unclassified)->toContain('comment')
        ->and($unclassified)->toContain('create')
        ->and($unclassified)->not->toContain('confirm')
        ->and($unclassified)->not->toContain('upload');
});

it('reports a RECORDED verb nobody ever declared', function () {
    // The actual leak scenario from the field: someone records a verb
    // carelessly and never adds it to the vocabulary. A declaration-only
    // universe would miss precisely this case.
    Storyfeed::verbs(ActivityVerb::class);

    Storyfeed::activity('order.margin_note', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['confirm'])]);

    $finding = feedFindings()->firstWhere('subject.verb', 'order.margin_note');

    expect($finding['code'])->toBe('feeds.unclassified')
        ->and($finding['severity'])->toBe(Severity::Warning->value)
        ->and($finding['message'])->toContain('nobody decided who may see it');
});

it('does not let an unfiltered feed count as a decision', function () {
    // The whole point. `'admin' => fn ($feed) => $feed` is the first thing every
    // app declares; if it classified anything, the check would never fire again.
    Storyfeed::verbs(ActivityVerb::class);

    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed->only(['confirm']),
        'admin' => fn (FeedBuilder $feed) => $feed,
    ]);

    expect(feedFindings()->where('code', 'feeds.unclassified')->pluck('subject.verb'))
        ->toContain('comment');
});

it('counts a DENIED verb as decided', function () {
    Storyfeed::verbs(ActivityVerb::class);

    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed->only(['confirm', 'upload'])->except(['comment']),
    ]);

    $unclassified = feedFindings()->where('code', 'feeds.unclassified')->pluck('subject.verb');

    // `comment` is named only in the DENYLIST — somebody looked at it and said
    // no, which is a decision. `create` is named nowhere, which is not.
    expect($unclassified)->not->toContain('comment')
        ->and($unclassified->all())->toBe(['create']);
});

it('counts a wildcard as deciding the verbs it covers', function () {
    Storyfeed::verbs(['order.placed' => 'Create', 'order.paid' => 'Update', 'internal.note' => 'Create']);

    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['order.*'])]);

    expect(feedFindings()->where('code', 'feeds.unclassified')->pluck('subject.verb')->all())
        ->toBe(['internal.note']);
});

it('flags a verb an allowlist names that is neither declared nor recorded', function () {
    // A typo in an allowlist is the quiet failure on the other side: the real
    // verb is not named, so it vanishes from the feed meant to carry it.
    Storyfeed::verbs(ActivityVerb::class);

    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed->only(['confirm', 'upload', 'comment', 'create', 'confrim']),
    ]);

    $finding = feedFindings()->firstWhere('code', 'feeds.unknown_verb');

    expect($finding['subject'])->toBe(['feed' => 'customer', 'verb' => 'confrim'])
        ->and($finding['severity'])->toBe(Severity::Warning->value)
        ->and($finding['message'])->toContain('likely a typo');
});

it('softens the unknown-verb finding for an app with no declared vocabulary', function () {
    // Same rule VerbDrift uses: before there is a vocabulary to deviate from,
    // an unrecognised verb is not evidence of anything.
    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['order.placed'])]);

    expect(feedFindings()->firstWhere('code', 'feeds.unknown_verb')['severity'])
        ->toBe(Severity::Info->value);
});

it('never demands an audience decision on the shipped default verbs', function () {
    // The 29 built-ins are not this app's vocabulary; asking someone to classify
    // `tentativeReject` would bury the real signal under a screenful of noise.
    Storyfeed::verbs(['order.placed' => 'Create']);

    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['order.placed'])]);

    expect(feedCodes())->toBe([]);
});

it('reports a registry that declares feeds but restricts none', function () {
    Storyfeed::verbs(ActivityVerb::class);

    Storyfeed::feeds(['admin' => fn (FeedBuilder $feed) => $feed->log()]);

    $finding = feedFindings()->firstWhere('code', 'feeds.none_restricted');

    expect($finding['severity'])->toBe(Severity::Info->value)
        ->and($finding['message'])->toContain('documentation, not a guardrail');
});

it('survives a preset that throws, and still classifies the others', function () {
    // Each preset gets its own try/catch rather than relying on Doctor's
    // run-wide one, which would cost every other preset's findings.
    Storyfeed::verbs(ActivityVerb::class);

    Storyfeed::feeds([
        'broken' => fn (FeedBuilder $feed) => throw new RuntimeException('boom'),
        'customer' => fn (FeedBuilder $feed) => $feed->only(['confirm']),
    ]);

    expect(feedFindings()->firstWhere('code', 'feeds.preset_failed')['subject'])
        ->toBe(['feed' => 'broken', 'exception' => RuntimeException::class])
        ->and(feedFindings()->where('code', 'feeds.unclassified')->pluck('subject.verb'))
        ->toContain('comment');
});

it('sees verbs a Story declared, live and through a cached manifest', function () {
    // Presets are boot-time closures and are untouched by storyfeed:cache. What
    // could break is this check reading the vocabulary: it must go through
    // registeredVerbs(), which resolves story-declared verbs whether they came
    // from a live compile or from the manifest.
    Storyfeed::stories([DeliveryWasConfirmed::class]);
    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['order.placed'])]);

    expect(feedFindings()->where('code', 'feeds.unclassified')->pluck('subject.verb'))
        ->toContain('confirm');

    // Now the cached path: a manifest whose verb the live compile never
    // produced, so a check reading past registeredVerbs() would miss it.
    Storyfeed::useCompiledStories([
        'grammar' => ['*.deliver' => ':actor delivered :object'],
        'aggregateGrammar' => [],
        'icons' => [],
        'verbs' => ['deliver' => 'Deliver'],
    ]);

    expect(feedFindings()->where('code', 'feeds.unclassified')->pluck('subject.verb'))
        ->toContain('deliver');
});

it('runs from the doctor command under --only=feeds', function () {
    Storyfeed::verbs(ActivityVerb::class);

    Storyfeed::feeds(['customer' => fn (FeedBuilder $feed) => $feed->only(['confirm'])]);

    $this->artisan('storyfeed:doctor --only=feeds')
        ->expectsOutputToContain('is named by no restricted feed')
        ->assertSuccessful();
});

it('is registered in the shipped check list', function () {
    expect(Storyfeed::checkNames())->toContain('feeds');
});
