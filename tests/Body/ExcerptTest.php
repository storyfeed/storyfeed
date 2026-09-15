<?php

use Illuminate\Support\HtmlString;
use Storyfeed\Body\Excerpt;
use Storyfeed\Contracts\FeedBody;

it('keeps a quotation out of the headline, with its form and version intact', function () {
    $body = Excerpt::make('The fee for each subsequent term is agreed at renewal.');
    $expected = [
        '$body' => 'Storyfeed/Body/Excerpt',
        '$v' => 1,
        'text' => 'The fee for each subsequent term is agreed at renewal.',
        'from' => null,
        // The name claims partiality, so the default says so and a caller who
        // has the whole thing turns it off.
        'truncated' => true,
    ];

    expect($body)->toBeInstanceOf(FeedBody::class)
        ->and($body->toArray())->toBe($expected)
        ->and($body->toPayload())->toBe($expected);

    $stored = json_decode(json_encode($body->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $props = array_diff_key($stored, array_flip([FeedBody::KEY, FeedBody::VERSION]));

    expect(Excerpt::upgrade($props, $stored[FeedBody::VERSION]))
        ->toBe(['text' => $expected['text'], 'from' => null, 'truncated' => true]);
});

it('carries an attribution and a whole-passage flag', function () {
    $stored = Excerpt::make('Fine by me.', from: 'Jasper', truncated: false)->toPayload();

    expect($stored['from'])->toBe('Jasper')
        ->and($stored['truncated'])->toBeFalse();
});

it('flattens markup out of a quotation, because a renderer may show it to a stranger', function () {
    // A quotation is text an app took from somewhere else. Rendering it as
    // markup is an injection sink pointed at a signed link. Authored rich text
    // is Markdown, which says so and is sanitised on the way out.
    expect(Excerpt::make(new HtmlString('<script>alert(1)</script>Guelph'))->toPayload()['text'])->toBe('alert(1)Guelph');

    foreach ([[42, '42'], [null, ''], [[], ''], [new stdClass, '']] as [$input, $text]) {
        expect(Excerpt::make($input)->toPayload()['text'])->toBe($text);
    }
});

it('normalizes malformed and unknown-version payloads without throwing', function () {
    foreach ([1, 0, 999] as $version) {
        expect(Excerpt::upgrade(['text' => 'A', 'from' => 'B', 'truncated' => 1, 'extra' => 'ignored'], $version))
            ->toBe(['text' => 'A', 'from' => 'B', 'truncated' => true]);

        foreach ([[], ['text' => null, 'from' => 42], ['text' => []]] as $payload) {
            expect(Excerpt::upgrade($payload, $version))->toBe(['text' => '', 'from' => null, 'truncated' => false]);
        }
    }
});
