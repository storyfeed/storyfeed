<?php

use Storyfeed\Diagnostics\Severity;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Axis;

/*
 * The check that needs no traffic.
 *
 * Every other coverage assertion here first publishes activities and waits for
 * a cluster; this one asks the registry a question it can already answer. So
 * these tests deliberately record NOTHING — an empty database is the point,
 * not an oversight, and a future refactor that starts reading rows will fail
 * here rather than quietly becoming the check it was written to be stronger
 * than.
 */

/** The consumer's recipe: everything about one photo on one day, verb omitted. */
function photoAxis(): Axis
{
    return Axis::make('photo')->key('oa!:oid!:d')->eligibleWhenMembers(min: 2);
}

it('says a verbless axis can never carry an aggregate sentence, with no activities at all', function () {
    Storyfeed::axes([photoAxis()]);

    $report = Storyfeed::doctor(['axes']);

    $finding = $report->withCode('axes.verbless_no_grammar')->first();

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe(['axis' => 'photo'])
        // The message says the CONSEQUENCE, and names the one the reader will
        // actually meet — the singular fallback admitted over a mixed group.
        ->and($finding->message)->toContain('no aggregate sentence that could be true of them')
        ->and($finding->message)->toContain('bare count')
        ->and($finding->message)->toContain('cannot see the verb');
});

it('offers the wildcard key as the fix, with tokens the recipe actually pins', function () {
    Storyfeed::axes([photoAxis()]);

    $fix = Storyfeed::doctor(['axes'])->withCode('axes.verbless_no_grammar')->first()->fix;

    expect($fix->registry)->toBe('aggregateGrammar')
        // Not `photo.<verb>`: a per-verb stub is the wrong shape for this axis.
        ->and($fix->key)->toBe('photo.*')
        ->and($fix->tokens)->toContain(':object')
        ->and($fix->tokens)->not->toContain(':verb')
        ->and($fix->snippet())->toContain("'photo.*' =>");
});

it('flags a per-verb template registered on an axis that cannot promise the verb', function () {
    Storyfeed::axes([photoAxis()]);
    Storyfeed::aggregateGrammar(['photo.uploaded' => ':count photos uploaded']);

    $finding = Storyfeed::doctor(['axes'])->withCode('axes.verbless_per_verb_grammar')->first();

    expect($finding)->not->toBeNull()
        ->and($finding->severity)->toBe(Severity::Warning)
        ->and($finding->subject)->toBe(['key' => 'photo.uploaded', 'axis' => 'photo', 'verb' => 'uploaded'])
        ->and($finding->message)->toContain('whichever member sorts first')
        // No Fix: adding `v` rewrites every hash on the axis, and rewriting the
        // sentence is prose. Neither is a paste-ready registry edit.
        ->and($finding->fix)->toBeNull();
});

it('names the wildcard rival when one is registered, because the head decides between them', function () {
    Storyfeed::axes([photoAxis()]);
    Storyfeed::aggregateGrammar([
        'photo.*' => ':count things happened to :object',
        'photo.uploaded' => ':count photos uploaded',
    ]);

    $report = Storyfeed::doctor(['axes']);

    // The wildcard answers the first finding entirely.
    expect($report->has('axes.verbless_no_grammar'))->toBeFalse();

    expect($report->withCode('axes.verbless_per_verb_grammar')->first()->message)
        ->toContain('`photo.*` renders instead');
});

it('is silent on the built-in axes, all four of which pin the verb', function () {
    expect(Storyfeed::doctor(['axes'])->all())->toBeEmpty();
});

it('says nothing about a verbless axis that is correctly served by one verb-agnostic sentence', function () {
    Storyfeed::axes([photoAxis()]);
    Storyfeed::aggregateGrammar(['photo.*' => ':count things happened to :object']);

    expect(Storyfeed::doctor(['axes'])->all())->toBeEmpty();
});

it('accepts a global fallback as the verb-agnostic answer', function () {
    Storyfeed::axes([photoAxis()]);
    Storyfeed::aggregateGrammar(['*.*' => ':count updates']);

    expect(Storyfeed::doctor(['axes'])->all())->toBeEmpty();
});

it('will not guess about a closure recipe or a row-backed bucket', function () {
    Storyfeed::axes([
        Axis::make('scene')->key(fn ($activity) => $activity->object_id)->pins(':object'),
        Axis::make('window')->rowBacked(),
    ]);

    // Neither declares `:verb`, and neither is asked to: a declaration is not
    // a mask, and this check would rather miss a gap than deny one.
    expect(Storyfeed::doctor(['axes'])->all())->toBeEmpty();
});

it('reports the axis the moment it is registered, before anything has grouped', function () {
    Storyfeed::axes([photoAxis()]);

    expect(Storyfeed::doctor(['axes'])->has('axes.verbless_no_grammar'))->toBeTrue()
        // The distinguishing property, pinned: no rows were written, and the
        // traffic-dependent check on the same subject has nothing to say.
        ->and(Storyfeed::doctor(['aggregates'])->has('aggregates.missing'))->toBeFalse();
});
