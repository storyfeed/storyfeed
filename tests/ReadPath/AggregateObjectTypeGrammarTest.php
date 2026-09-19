<?php

use Storyfeed\Facades\Storyfeed;

/**
 * Aggregate grammar gains a second dimension: `axis.objectType.verb`.
 *
 * Singular grammar is keyed `objectType.verb`, so two acts sharing a verb stay
 * apart by their object. Aggregate grammar was keyed `axis.verb` only — which
 * is invisible while an app's verbs are bespoke, and becomes a collision the
 * moment it moves onto a shared vocabulary. Found converting a consumer's
 * doctrine verbs, where two families that read as different sentences landed
 * on one aggregate key.
 */
it('prefers the object-typed aggregate key over the plain one', function () {
    Storyfeed::aggregateGrammar([
        'repeat.update' => ':actors changed :count things',
        'repeat.clause_template.update' => ':actors reworded :count clauses in the library',
        'repeat.clause_variant.update' => ':actors reworded :count wordings',
    ]);

    expect(Storyfeed::aggregateTemplate('repeat', 'update', 'clause_template'))
        ->toBe(':actors reworded :count clauses in the library')
        ->and(Storyfeed::aggregateTemplate('repeat', 'update', 'clause_variant'))
        ->toBe(':actors reworded :count wordings');
});

it('falls back to the plain key when no object-typed one is registered', function () {
    Storyfeed::aggregateGrammar(['repeat.update' => ':actors changed :count things']);

    expect(Storyfeed::aggregateTemplate('repeat', 'update', 'clause_template'))
        ->toBe(':actors changed :count things');
});

it('leaves every existing two-segment key meaning exactly what it meant', function () {
    /*
     * The whole point of trying the qualified key FIRST rather than instead:
     * an app that never writes a three-segment key must not be able to tell
     * this feature exists.
     */
    Storyfeed::aggregateGrammar([
        'repeat.update' => ':actors changed :count things',
        'repeat.*' => ':actors did :count things',
        '*.update' => ':actors updated :count things',
        '*.*' => ':count activities',
    ]);

    expect(Storyfeed::aggregateTemplate('repeat', 'update'))->toBe(':actors changed :count things')
        ->and(Storyfeed::aggregateTemplate('repeat', 'retire'))->toBe(':actors did :count things')
        ->and(Storyfeed::aggregateTemplate('burst', 'update'))->toBe(':actors updated :count things')
        ->and(Storyfeed::aggregateTemplate('burst', 'retire'))->toBe(':count activities');
});

it('reports the qualified key to coverage, so the doctor sees what renders', function () {
    Storyfeed::aggregateGrammar([
        'repeat.update' => ':actors changed :count things',
        'repeat.clause_template.update' => ':actors reworded :count clauses in the library',
    ]);

    expect(Storyfeed::aggregateTemplateKey('repeat', 'update', 'clause_template'))
        ->toBe('repeat.clause_template.update')
        ->and(Storyfeed::aggregateTemplateKey('repeat', 'update'))
        ->toBe('repeat.update');
});
