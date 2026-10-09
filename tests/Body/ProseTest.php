<?php

use Storyfeed\Body\Prose;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\Exceptions\IncompleteFeedValue;

it('round-trips authored source with its form and version intact', function () {
    $content = "## Release notes\n\n**Hello** <script>alert('source')</script>";
    $body = Prose::markdown($content);
    $expected = [
        '$body' => 'Storyfeed/Body/Prose',
        '$v' => 2,
        'content' => $content,
        'mediaType' => 'text/markdown',
    ];

    expect($body)->toBeInstanceOf(FeedBody::class)
        ->and($body->toArray())->toBe($expected)
        ->and($body->toPayload())->toBe($expected);

    $stored = json_decode(json_encode($body->toArray(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    $props = array_diff_key($stored, array_flip([FeedBody::KEY, FeedBody::VERSION]));
    $upgraded = Prose::upgrade($props, $stored[FeedBody::VERSION]);

    expect($upgraded)->toBe(['content' => $content, 'mediaType' => 'text/markdown', 'verbatim' => false, 'title' => null])
        ->and(Prose::markdown($upgraded['content'])->toPayload())->toBe($expected);
});

it('preserves the source factory coercions', function () {
    $stringable = new class implements Stringable
    {
        public function __toString(): string
        {
            return '**text**';
        }
    };

    foreach ([[42, '42'], [true, '1'], [false, ''], [[], ''], [new stdClass, ''], [$stringable, '**text**']] as [$input, $expected]) {
        expect(Prose::make($input)->toPayload()['content'])->toBe($expected)
            ->and(Prose::make()->content($input)->toPayload()['content'])->toBe($expected);
    }

    // Null clears the text, which is then caught when the body is used.
    expect(fn () => Prose::make('x')->content(null)->toPayload())->toThrow(IncompleteFeedValue::class);
});

it('normalizes malformed and unknown-version payloads without throwing', function () {
    // Before v2 an absent encoding is the markdown it replaced; from v2 it is
    // the default, plain text.
    foreach ([1 => 'text/markdown', 0 => 'text/markdown', 2 => 'text/plain', 999 => 'text/plain'] as $version => $mediaType) {
        expect(Prose::upgrade(['content' => '**text**', 'extra' => 'ignored'], $version))
            ->toBe(['content' => '**text**', 'mediaType' => $mediaType, 'verbatim' => false, 'title' => null]);

        foreach ([[], ['content' => null], ['content' => 42], ['content' => []]] as $payload) {
            expect(Prose::upgrade($payload, $version))
                ->toBe(['content' => '', 'mediaType' => $mediaType, 'verbatim' => false, 'title' => null]);
        }
    }
});

it('names an encoding per constructor, so the class is not one value of its own field', function () {
    expect(rendered(Prose::make('x'))['mediaType'])->toBe('text/plain')
        ->and(Prose::make('x')->toPayload())->not->toHaveKey('mediaType')
        ->and(Prose::markdown('x')->toPayload()['mediaType'])->toBe('text/markdown')
        ->and(Prose::html('<b>x</b>')->toPayload()['mediaType'])->toBe('text/html')
        ->and(Prose::code('<?php', 'text/x-php')->toPayload()['mediaType'])->toBe('text/x-php');
});

it('marks reproduced-exactly as a fact, and only where it is one', function () {
    expect(rendered(Prose::make('x'))['verbatim'])->toBeFalse()
        ->and(rendered(Prose::markdown('x'))['verbatim'])->toBeFalse()
        ->and(Prose::verbatim("a\n  b")->toPayload()['verbatim'])->toBeTrue()
        ->and(Prose::code('<?php', 'text/x-php')->toPayload()['verbatim'])->toBeTrue();
});

it('keeps the whitespace somebody typed, which is the whole point of verbatim', function () {
    $output = "Deploying…\n  web   ok\n  queue ok";

    expect(Prose::verbatim($output)->toPayload()['content'])->toBe($output);
});

it('carries a title, so a snippet can say which file it came from', function () {
    expect(Prose::code('SELECT 1', 'text/x-sql', title: 'report.sql')->toPayload()['title'])->toBe('report.sql')
        ->and(rendered(Prose::make('x'))['title'])->toBeNull();
});

it('reads a row written before the encoding travelled as the one it could only have been', function () {
    expect(Prose::upgrade(['content' => '# Hi'], 1))
        ->toBe(['content' => '# Hi', 'mediaType' => 'text/markdown', 'verbatim' => false, 'title' => null]);
});
