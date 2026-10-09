<?php

use Storyfeed\Exceptions\IncompleteFeedValue;
use Storyfeed\FeedLink;

it('carries one shape everywhere: label, href, modal and attributes', function () {
    expect(FeedLink::make('The recall notice', 'https://example.test/recalls/9')->toPayload())
        ->toBe(['label' => 'The recall notice', 'href' => 'https://example.test/recalls/9', 'modal' => false, 'attributes' => []]);

    expect(FeedLink::to('/photos/1')->modal()->attributes(['target' => '_blank'])->attributes('rel', 'noopener')->toPayload())
        ->toBe(['label' => null, 'href' => '/photos/1', 'modal' => true, 'attributes' => ['target' => '_blank', 'rel' => 'noopener']]);
});

it('stores no location for the entity\'s own link, and says so by name', function () {
    /*
     * A stored href ages — routes get renamed, slugs change, signed links
     * expire. toEntity() resolves against the entity's link at read time,
     * every time, and `href: null` is the shape such a link always had.
     */
    expect(FeedLink::toEntity('N201 Saffron Butter Rice')->modal()->toPayload())
        ->toBe(['label' => 'N201 Saffron Butter Rice', 'href' => null, 'modal' => true, 'attributes' => []]);
});

it('infers nothing from a missing href', function () {
    /*
     * `FeedLink::make($photo->title)` does not say where it goes, so it
     * throws when written, naming the method. toEntity() is the explicit way.
     */
    expect(fn () => FeedLink::make('A dish')->toPayload())
        ->toThrow(IncompleteFeedValue::class, 'FeedLink has no href. Call ->href(…)');
});

it('never turns a plain string into a link', function () {
    /*
     * A field that accepts `string|FeedLink` uses the two to mean different
     * things: text that leads nowhere, and text that leads somewhere. If this
     * method coerced, every title written before the field widened would
     * become clickable on the day it widened.
     */
    expect(FeedLink::from('N201 Saffron Butter Rice'))->toBeNull();
});

it('rehydrates a stored link and degrades anything malformed to null', function () {
    expect(FeedLink::from(['label' => 'A dish', 'href' => 'https://example.test', 'modal' => true, 'attributes' => ['target' => '_blank']]))
        ->toEqual(FeedLink::make('A dish', 'https://example.test')->modal()->attributes(['target' => '_blank']));

    // A row written before toEntity() named it, and one written after.
    expect(FeedLink::from(['label' => 'A dish', 'href' => null]))->toEqual(FeedLink::toEntity('A dish'))
        ->and(FeedLink::from(['label' => 'A dish', 'href' => '']))->toEqual(FeedLink::toEntity('A dish'))
        ->and(FeedLink::from(['href' => null]))->toEqual(FeedLink::toEntity());

    // A label is optional on the class; a body that draws one requires it.
    expect(FeedLink::from(['href' => 'https://example.test'])?->label)->toBeNull();

    // Malformed renders as nothing, never as a broken row.
    foreach ([null, 7, [], ['label' => 'A dish'], ['label' => 3, 'href' => null], ['href' => 9]] as $malformed) {
        expect(FeedLink::from($malformed))->toBeNull();
    }

    // A malformed suggestion is dropped; the link survives.
    expect(FeedLink::from(['label' => 'A', 'href' => '/a', 'modal' => 'yes', 'attributes' => 'target'])?->toPayload())
        ->toBe(['label' => 'A', 'href' => '/a', 'modal' => false, 'attributes' => []]);
});

it('is idempotent on an instance, so a caller may normalize without checking', function () {
    $link = FeedLink::make('A dish', '/dishes/1');

    expect(FeedLink::from($link))->toBe($link);
});
