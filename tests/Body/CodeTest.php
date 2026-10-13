<?php

use Illuminate\Support\HtmlString;
use Storyfeed\Body\Code;
use Storyfeed\Exceptions\IncompleteFeedValue;
use Storyfeed\FeedBody;

it('writes a title, a language and the source', function () {
    $source = "- drop('orders_users')\n+ rename('orders_users', 'order_users')";

    $body = Code::make($source)->language('diff')->title('Migration');

    expect($body)->toBeInstanceOf(FeedBody::class)
        ->and($body->toPayload())->toBe([
            '$body' => 'Storyfeed/Body/Code',
            '$v' => 1,
            '$fallback' => 'Migration',
            'title' => 'Migration',
            'language' => 'diff',
            'source' => $source,
        ]);
});

it('is bare code at its smallest, falling back to its first line', function () {
    $source = "<?php\n\nreturn [\n];";

    $body = Code::make($source);

    expect($body->toPayload())->toBe([
        '$body' => 'Storyfeed/Body/Code',
        '$v' => 1,
        '$fallback' => '<?php',
        'source' => $source,
    ])->and(rendered($body))->toBe([
        'title' => null,
        'language' => null,
        'source' => $source,
    ]);
});

it('falls back to the title, else the first line of the source, without its line ending', function () {
    expect(Code::make("up()\r\ndown()")->toPayload()['$fallback'])->toBe('up()')
        ->and(Code::make('up()')->toPayload()['$fallback'])->toBe('up()')
        ->and(Code::make('up()')->title('Migration')->toPayload()['$fallback'])->toBe('Migration')
        ->and(Code::make('')->toPayload())->not->toHaveKey('$fallback');
});

it('keeps the source to text, flattening markup out of it', function () {
    // Code is drawn as the characters somebody wrote, so markup in it is
    // stripped on the way in, never kept as an instruction.
    expect(Code::make(new HtmlString('<script>alert(1)</script>up()'))->toPayload()['source'])->toBe('alert(1)up()');

    foreach ([[42, '42'], [[], ''], [new stdClass, '']] as [$input, $text]) {
        expect(Code::make($input)->toPayload()['source'])->toBe($text)
            ->and(Code::make()->source($input)->toPayload()['source'])->toBe($text);
    }
});

it('requires the source, naming the method', function () {
    expect(fn () => Code::make()->toPayload())
        ->toThrow(IncompleteFeedValue::class, 'Code has no source. Call ->source(…) on it, or pass source: to Code::make().');
});

it('builds the same body chained or named', function () {
    expect(Code::make()->source('up()')->language('php')->title('Migration')->toPayload())
        ->toBe(Code::make(source: 'up()', language: 'php', title: 'Migration')->toPayload());
});

it('upgrades a malformed row to its source and no language, never an error', function () {
    expect(Code::upgrade(['title' => 'Migration', 'language' => 42, 'source' => 'up()'], 1))
        ->toBe(['title' => 'Migration', 'language' => null, 'source' => 'up()'])
        ->and(Code::upgrade(['language' => ''], 1)['language'])->toBeNull()
        ->and(Code::upgrade([], 1))->toBe(['title' => null, 'language' => null, 'source' => '']);
});
