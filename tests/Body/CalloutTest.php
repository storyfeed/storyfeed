<?php

use Storyfeed\Body\Callout;
use Storyfeed\Exceptions\IncompleteFeedValue;
use Storyfeed\FeedBody;

it('writes a title, the note and its intent', function () {
    $body = Callout::make('This order ships from the backup warehouse.')
        ->intent('warning')
        ->title('Split warehouse');

    expect($body)->toBeInstanceOf(FeedBody::class)
        ->and($body->toPayload())->toBe([
            '$body' => 'Storyfeed/Body/Callout',
            '$v' => 1,
            '$fallback' => 'Split warehouse',
            'title' => 'Split warehouse',
            'content' => 'This order ships from the backup warehouse.',
            'intent' => 'warning',
        ]);
});

it('defaults its intent to note, written only when it is not the default', function () {
    $note = 'Storyfeed is pre-1.0: minor versions may break the API until 1.0 ships.';
    $body = Callout::make($note)->title('Pin a version');

    expect($body->toPayload())->toBe([
        '$body' => 'Storyfeed/Body/Callout',
        '$v' => 1,
        '$fallback' => 'Pin a version',
        'title' => 'Pin a version',
        'content' => $note,
    ])->and(rendered($body))->toBe([
        'title' => 'Pin a version',
        'content' => $note,
        'intent' => 'note',
    ])->and(Callout::make('A')->intent('note')->toPayload())->toBe(Callout::make('A')->toPayload())
        ->and(rendered(Callout::make('A')))->toHaveKey('intent');
});

it('takes an app-owned intent word, and spells the common ones', function () {
    // The same rules a glyph's intent follows: core ships no vocabulary and
    // validates none, and a renderer maps the word to its palette.
    expect(Callout::make('A')->intent('warm')->toPayload()['intent'])->toBe('warm')
        ->and(Callout::make('A')->info()->toPayload()['intent'])->toBe('info')
        ->and(Callout::make('A')->success()->toPayload()['intent'])->toBe('success')
        ->and(Callout::make('A')->warning()->toPayload()['intent'])->toBe('warning')
        ->and(Callout::make('A')->danger()->toPayload()['intent'])->toBe('danger')
        ->and(Callout::make('A', intent: 'warning')->toPayload()['intent'])->toBe('warning');
});

it('falls back to the note itself when it has no title', function () {
    expect(Callout::make('This document did not reach the client.')->toPayload()['$fallback'])
        ->toBe('This document did not reach the client.')
        ->and(Callout::make('')->toPayload())->not->toHaveKey('$fallback');
});

it('requires the note, naming the method', function () {
    expect(fn () => Callout::make()->toPayload())
        ->toThrow(IncompleteFeedValue::class, 'Callout has no content. Call ->content(…) on it, or pass content: to Callout::make().');
});

it('builds the same body chained or named', function () {
    expect(Callout::make()->content('A')->title('B')->intent('warning')->toPayload())
        ->toBe(Callout::make(content: 'A', title: 'B', intent: 'warning')->toPayload());
});

it('upgrades a malformed row to its note and a note intent, never an error', function () {
    expect(Callout::upgrade(['title' => 'A', 'intent' => 42, 'content' => 'B'], 1))
        ->toBe(['title' => 'A', 'content' => 'B', 'intent' => 'note'])
        ->and(Callout::upgrade(['intent' => ''], 1)['intent'])->toBe('note')
        ->and(Callout::upgrade([], 1))->toBe(['title' => null, 'content' => '', 'intent' => 'note']);
});
