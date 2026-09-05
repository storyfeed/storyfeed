<?php

use Illuminate\Support\Collection;
use Storyfeed\ActivityStreams\ActivityType;
use Storyfeed\Diagnostics\Severity;
use Storyfeed\Exceptions\FeedMisconfigured;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Workbench\App\Enums\ActivityVerb;
use Workbench\App\Feeds\CustomerFeed;
use Workbench\App\Feeds\GreedyFeed;
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

    expect($finding['subject']['feed'])->toBe('customer')
        ->and($finding['subject']['verb'])->toBe('confrim')
        ->and($finding['severity'])->toBe(Severity::Warning->value)
        ->and($finding['message'])->toContain('likely a typo')
        // Every finding that names a feed points AT it. Closures reflect too,
        // so this is not a privilege of the class form.
        ->and($finding['subject']['source'])->toContain('FeedCoverageTest.php:')
        ->and($finding['message'])->toContain('Declared in');
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

    $broken = feedFindings()->firstWhere('code', 'feeds.preset_failed')['subject'];

    expect($broken['feed'])->toBe('broken')
        ->and($broken['exception'])->toBe(RuntimeException::class)
        ->and($broken['source'])->toContain('FeedCoverageTest.php:')
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

it('classifies verbs a Feed CLASS named, without being able to construct it', function () {
    // The asymmetry the two hooks exist for: a customer feed takes an order in
    // its constructor, so doctor can never build one — but it can still read
    // what the class DECLARED, which is the only thing this check needs.
    Storyfeed::verbs(ActivityVerb::class);
    Storyfeed::feeds([CustomerFeed::class]);

    expect(feedFindings()->where('code', 'feeds.unclassified')->pluck('subject.verb'))
        ->toContain('confirm')
        ->not->toContain('order.placed');
});

it('points a finding at the class file, not just the feed name', function () {
    Storyfeed::verbs(ActivityVerb::class);
    Storyfeed::feeds([CustomerFeed::class]);

    // The Story layer's jump, on the read side: `app/Feeds/CustomerFeed.php:19`
    // rather than a string key. Closures reflect too — what the class adds is
    // an identity that survives someone reordering the provider array.
    $finding = feedFindings()->firstWhere('code', 'feeds.unknown_verb');

    expect($finding['subject']['source'])->toContain('CustomerFeed.php:')
        ->and($finding['message'])->toContain('Declared in');
});

it('reports a define() that reads constructor state, and keeps checking the others', function () {
    // define() is contractually forbidden from touching what the constructor
    // was given, because doctor runs it on an instance built without one. The
    // failure is already handled: it is one feed's finding, not the run's.
    Storyfeed::verbs(ActivityVerb::class);
    Storyfeed::feeds(['greedy' => GreedyFeed::class, 'customer' => CustomerFeed::class]);

    expect(feedCodes())->toContain('feeds.preset_failed')
        ->and(feedFindings()->firstWhere('code', 'feeds.preset_failed')['subject']['feed'])->toBe('greedy')
        ->and(feedFindings()->where('code', 'feeds.unclassified')->pluck('subject.verb'))->toContain('confirm');
});

it('checks class feeds identically through a cached manifest', function () {
    // storyfeed:cache is a NO-OP for feeds by construction: a feed compiles to
    // behaviour, not data, so it never enters the manifest. What must not break
    // is this check's vocabulary, which does come from there.
    Storyfeed::stories([DeliveryWasConfirmed::class]);
    Storyfeed::feeds([CustomerFeed::class]);

    Storyfeed::useCompiledStories([
        'grammar' => ['*.deliver' => ':actor delivered :object'],
        'aggregateGrammar' => [],
        'icons' => [],
        'verbs' => ['deliver' => 'Deliver'],
    ]);

    expect(feedFindings()->where('code', 'feeds.unclassified')->pluck('subject.verb'))
        ->toContain('deliver')
        ->not->toContain('order.placed');
});

it('counts a single verb() feed as a restriction that classifies its verb', function () {
    // ->verb('confirm') narrows a feed exactly as only(['confirm']) does. Read
    // through verbFilter() alone it looked wide open: classifying nothing, and
    // hiding a typo the same way a typo'd allowlist entry hides one.
    Storyfeed::verbs(['confirm' => ActivityType::Update, 'internal.note' => ActivityType::Create]);
    Storyfeed::feeds(['confirmations' => fn (FeedBuilder $feed) => $feed->verb('confirm')]);

    $codes = collect(Storyfeed::doctor(['feeds'])->all())->pluck('code')->all();

    expect($codes)->not->toContain('feeds.none_restricted')
        ->and($codes)->toContain('feeds.unclassified');

    $unclassified = collect(Storyfeed::doctor(['feeds'])->all())
        ->filter(fn ($finding) => $finding->code === 'feeds.unclassified')
        ->pluck('subject.verb')->all();

    // `confirm` was decided by the single-verb feed; `internal.note` was not.
    expect($unclassified)->toBe(['internal.note']);
});

it('reports a typo in a single verb() feed, as it does for an allowlist', function () {
    Storyfeed::verbs(['confirm' => ActivityType::Update]);
    Storyfeed::feeds(['confirmations' => fn (FeedBuilder $feed) => $feed->verb('confrim')]);

    expect(collect(Storyfeed::doctor(['feeds'])->all())->pluck('code')->all())
        ->toContain('feeds.unknown_verb');
});

it('reports a verb carried only by a declared-unrestricted feed as Info, not Warning', function () {
    // An operations portal carried eleven permanent "nobody decided" warnings
    // for a decision that WAS made — the decision was "everyone". Saying so
    // in code changes the severity and nothing else.
    Storyfeed::verbs(ActivityVerb::class);

    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed->only(['confirm', 'upload']),
        'portal' => fn (FeedBuilder $feed) => $feed->unrestricted()->summary(),
    ]);

    $finding = feedFindings()->firstWhere('subject.verb', 'comment');

    expect($finding['code'])->toBe('feeds.unrestricted')
        ->and($finding['severity'])->toBe(Severity::Info->value)
        ->and($finding['subject']['feeds'])->toBe('portal')
        ->and($finding['message'])->toContain('by declaration rather than by omission')
        ->and(feedCodes())->not->toContain('feeds.unclassified');
});

it('keeps an OMITTED allowlist a Warning even beside a declared-unrestricted feed', function () {
    // Declaring is an act; forgetting is not. `->unrestricted()` speaks only
    // for the feed it is written on, so the open `admin` feed here decides
    // nothing — and the verbs are still Info, because the WORLD feed carries
    // them by declaration. What must not happen is the two collapsing into
    // each other in the other direction: an open feed alone stays a Warning.
    Storyfeed::verbs(ActivityVerb::class);

    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed->only(['confirm']),
        'admin' => fn (FeedBuilder $feed) => $feed,
    ]);

    expect(feedFindings()->firstWhere('subject.verb', 'comment')['severity'])
        ->toBe(Severity::Warning->value);
});

it('still surfaces a verb recorded after the declaration — unrestricted decides nothing', function () {
    // The hole the docblock was written around: a declaration that made
    // covered verbs DECIDED would be green on day one and green on the day
    // someone records `order.margin_note`. So the twelfth verb is reported on
    // every run, just not as an open problem.
    Storyfeed::verbs(ActivityVerb::class);

    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed->only(['confirm', 'upload', 'comment', 'create']),
        'portal' => fn (FeedBuilder $feed) => $feed->unrestricted(),
    ]);

    expect(feedCodes())->toBe([]);

    Storyfeed::activity('order.margin_note', Delivery::create(['tracking_number' => 'TN-1']))->publish();

    $finding = feedFindings()->firstWhere('subject.verb', 'order.margin_note');

    expect($finding['code'])->toBe('feeds.unrestricted')
        ->and($finding['severity'])->toBe(Severity::Info->value);
});

it('stops failing CI under --fail-on=warning once the world feed says so', function () {
    Storyfeed::verbs(ActivityVerb::class);

    Storyfeed::feeds([
        'customer' => fn (FeedBuilder $feed) => $feed->only(['confirm']),
        'portal' => fn (FeedBuilder $feed) => $feed->unrestricted(),
    ]);

    $this->artisan('storyfeed:doctor --only=feeds --fail-on=warning')
        ->expectsOutputToContain('declares itself unrestricted')
        ->assertSuccessful();
});

it('refuses a declaration that contradicts itself', function () {
    // `->only([...])->unrestricted()` is one declaration saying two things:
    // the read path would honour the filter while doctor honoured the word.
    expect(fn () => (new FeedBuilder)->only(['confirm'])->unrestricted())
        ->toThrow(FeedMisconfigured::class, 'already declares only()/except()');

    expect(fn () => (new FeedBuilder)->verb('confirm')->unrestricted())
        ->toThrow(FeedMisconfigured::class);
});

it('still lets a call site narrow a declared-unrestricted feed', function () {
    // Narrowing after the declaration is what a call site is for, and the
    // filter is what the read path applies — declaredUnrestricted() is not a
    // widening and never reaches SQL.
    Storyfeed::feeds(['portal' => fn (FeedBuilder $feed) => $feed->unrestricted()]);

    $builder = Storyfeed::feed('portal')->only(['confirm']);

    expect($builder->isVerbRestricted())->toBeTrue()
        ->and($builder->admits('comment'))->toBeFalse();
});

it('does not turn a registry with no restricted feed into a guardrail by declaring it', function () {
    // One unrestricted feed among restricted ones is the case Info is right
    // for. A registry that restricts NOTHING is a true statement about the
    // app, and the existing Info already says it.
    Storyfeed::verbs(ActivityVerb::class);

    Storyfeed::feeds(['portal' => fn (FeedBuilder $feed) => $feed->unrestricted()]);

    expect(feedCodes())->toBe(['feeds.none_restricted']);
});
