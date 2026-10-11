<?php

use Illuminate\Support\HtmlString;
use Storyfeed\Body\Excerpt;
use Storyfeed\Contracts\FeedBody;

it('keeps a quotation out of the headline, with its form and version intact', function () {
    $body = Excerpt::make('The fee for each subsequent term is agreed at renewal.');
    $expected = [
        '$body' => 'Storyfeed/Body/Excerpt',
        '$v' => 3,
        'text' => 'The fee for each subsequent term is agreed at renewal.',
    ];

    expect($body)->toBeInstanceOf(FeedBody::class)
        ->and($body->toArray())->toBe($expected)
        ->and($body->toPayload())->toBe($expected);

    $stored = json_decode(json_encode($body->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $props = array_diff_key($stored, array_flip([FeedBody::KEY, FeedBody::VERSION]));

    // Most excerpts are whole, so the default says so and a caller who cut
    // the text says truncated().
    expect(Excerpt::upgrade($props, $stored[FeedBody::VERSION]))
        ->toBe(['text' => $expected['text'], 'from' => null, 'truncated' => false]);
});

it('carries an attribution and a truncated flag', function () {
    $stored = Excerpt::make('The fee for each…', from: 'Harbor Retainer')->truncated()->toPayload();

    expect($stored['from'])->toBe('Harbor Retainer')
        ->and($stored['truncated'])->toBeTrue()
        ->and(Excerpt::make('A')->truncated()->truncated(false)->toPayload())->not->toHaveKey('truncated');
});

it('reads each stored version without the flag as the default that version left out', function () {
    // Nothing stored is rewritten: a v1 row always wrote the flag, so one
    // without it was written by hand and reads as whole; v2 left out true;
    // v3 leaves out false.
    expect(Excerpt::upgrade(['text' => 'A'], 1)['truncated'])->toBeFalse()
        ->and(Excerpt::upgrade(['text' => 'A'], 2)['truncated'])->toBeTrue()
        ->and(Excerpt::upgrade(['text' => 'A'], 3)['truncated'])->toBeFalse();

    // A row that wrote the flag means what it says in every version.
    foreach ([1, 2, 3] as $version) {
        expect(Excerpt::upgrade(['text' => 'A', 'truncated' => true], $version)['truncated'])->toBeTrue()
            ->and(Excerpt::upgrade(['text' => 'A', 'truncated' => false], $version)['truncated'])->toBeFalse();
    }
});

it('flattens markup out of a quotation, because a renderer may show it to a stranger', function () {
    // A quotation is text an app took from somewhere else. Rendering it as
    // markup is an injection sink pointed at a signed link. Authored rich text
    // is Markdown, which says so and is sanitised on the way out.
    expect(Excerpt::make(new HtmlString('<script>alert(1)</script>Guelph'))->toPayload()['text'])->toBe('alert(1)Guelph');

    foreach ([[42, '42'], [[], ''], [new stdClass, '']] as [$input, $text]) {
        expect(Excerpt::make($input)->toPayload()['text'])->toBe($text)
            ->and(Excerpt::make()->text($input)->toPayload()['text'])->toBe($text);
    }

    // Null handed to the setter is text that happens to be empty; null handed
    // to make() is no text given, which is caught when the body is used.
    expect(Excerpt::make()->text(null)->toPayload()['text'])->toBe('');
});

it('normalizes malformed and unknown-version payloads without throwing', function () {
    foreach ([1, 0, 999] as $version) {
        expect(Excerpt::upgrade(['text' => 'A', 'from' => 'B', 'truncated' => 1, 'extra' => 'ignored'], $version))
            ->toBe(['text' => 'A', 'from' => 'B', 'truncated' => true]);

        // Only v2 left out true; any other version without the flag is whole.
        foreach ([[], ['text' => null, 'from' => 42], ['text' => []]] as $payload) {
            expect(Excerpt::upgrade($payload, $version))->toBe(['text' => '', 'from' => null, 'truncated' => false]);
        }
    }
});
