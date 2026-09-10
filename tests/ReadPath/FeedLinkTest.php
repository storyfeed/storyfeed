<?php

use Storyfeed\FeedLink;

it('stores a label and, by default, no location at all', function () {
    /*
     * THE DEFAULT IS THE WHOLE POINT. A stored href ages — routes get renamed,
     * slugs change, signed links expire — which is the same rule that keeps a
     * FeedImage's src out of a snapshot. A link whose href is null resolves
     * against the entity it belongs to, at read time, every time.
     */
    expect(FeedLink::make('N201 Saffron Butter Rice')->toPayload())
        ->toBe(['label' => 'N201 Saffron Butter Rice', 'href' => null]);

    expect(FeedLink::make('The recall notice', 'https://example.test/recalls/9')->toPayload())
        ->toBe(['label' => 'The recall notice', 'href' => 'https://example.test/recalls/9']);
});

it('never turns a plain string into a link', function () {
    /*
     * A field that accepts `string|FeedLink` uses the two to mean different
     * things: text that leads nowhere, and text that leads to the entity. If
     * this method coerced, every title written before the field widened would
     * become clickable on the day it widened — an available target read as an
     * instruction, which is the defect this vocabulary keeps producing.
     */
    expect(FeedLink::from('N201 Saffron Butter Rice'))->toBeNull();
});

it('rehydrates a stored link and degrades anything malformed to null', function () {
    expect(FeedLink::from(['label' => 'A dish', 'href' => null]))
        ->toEqual(FeedLink::make('A dish'));

    expect(FeedLink::from(['label' => 'A dish', 'href' => 'https://example.test']))
        ->toEqual(FeedLink::make('A dish', 'https://example.test'));

    // An empty href is not a location, and an empty label is not a link.
    expect(FeedLink::from(['label' => 'A dish', 'href' => ''])?->href)->toBeNull();

    // Malformed renders as nothing, never as a broken row — the rule an
    // unknown detail follows, one layer down.
    foreach ([null, 7, [], ['href' => 'https://example.test'], ['label' => ''], ['label' => 3]] as $malformed) {
        expect(FeedLink::from($malformed))->toBeNull();
    }
});

it('is idempotent on an instance, so a caller may normalize without checking', function () {
    $link = FeedLink::make('A dish');

    expect(FeedLink::from($link))->toBe($link);
});

it('carries no location facts, because navigating is not fetching', function () {
    /*
     * The tripwire, as a test. FeedResource models the same AS2 term and
     * carries `mediaType` because something has to be decided before a file is
     * opened. This one carries a label and an href. The day a modal flag, an
     * icon or a target attribute is proposed for it, the name is wrong again
     * and the class that died on 2026-09-05 is being rebuilt.
     */
    expect(array_keys(FeedLink::make('A dish')->toPayload()))->toBe(['label', 'href']);
});
