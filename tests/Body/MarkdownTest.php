<?php

use Storyfeed\Body\Markdown;
use Storyfeed\Contracts\FeedBody;

it('round-trips authored source with its form and version intact', function () {
    $content = "## Release notes\n\n**Hello** <script>alert('source')</script>";
    $body = Markdown::make($content);
    $expected = [
        '$body' => 'Storyfeed/Body/Markdown',
        '$v' => 1,
        'content' => $content,
        'mediaType' => 'text/markdown',
    ];

    expect($body)->toBeInstanceOf(FeedBody::class)
        ->and($body->toArray())->toBe($expected)
        ->and($body->toPayload())->toBe($expected);

    $stored = json_decode(json_encode($body->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $props = array_diff_key($stored, array_flip([FeedBody::KEY, FeedBody::VERSION]));
    $upgraded = Markdown::upgrade($props, $stored[FeedBody::VERSION]);

    expect($upgraded)->toBe(['content' => $content, 'mediaType' => 'text/markdown'])
        ->and(Markdown::make($upgraded['content'])->toPayload())->toBe($expected);
});

it('preserves the source factory coercions', function () {
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return '**text**';
        }
    };

    foreach ([[42, '42'], [true, '1'], [false, ''], [null, ''], [[], ''], [new stdClass, ''], [$stringable, '**text**']] as [$input, $expected]) {
        expect(Markdown::make($input)->toPayload()['content'])->toBe($expected);
    }
});

it('normalizes malformed and unknown-version payloads without throwing', function () {
    foreach ([1, 0, 999] as $version) {
        expect(Markdown::upgrade(['content' => '**text**', 'extra' => 'ignored'], $version))
            ->toBe(['content' => '**text**', 'mediaType' => 'text/markdown']);

        foreach ([[], ['content' => null], ['content' => 42], ['content' => []]] as $payload) {
            expect(Markdown::upgrade($payload, $version))
                ->toBe(['content' => '', 'mediaType' => 'text/markdown']);
        }
    }
});
