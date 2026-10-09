<?php

use Illuminate\Support\HtmlString;
use Storyfeed\Body\Prose;
use Storyfeed\Body\Table;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\FeedLink;

it('copies Artisan\'s table($headers, $rows), and stores a row a reader can read', function () {
    $body = Table::make(['Plan', 'Seats', 'Price'], [
        ['Starter', 3, '$9'],
        ['Team', 25, '$49'],
    ])->title('Pricing changes');

    expect($body)->toBeInstanceOf(FeedBody::class)
        ->and($body->toPayload())->toBe([
            '$body' => 'Storyfeed/Body/Table',
            '$v' => 1,
            '$fallback' => 'Pricing changes',
            'title' => 'Pricing changes',
            'headers' => ['Plan', 'Seats', 'Price'],
            'rows' => [['Starter', 3, '$9'], ['Team', 25, '$49']],
        ])
        ->and(rendered($body))->toBe([
            'title' => 'Pricing changes',
            'headers' => ['Plan', 'Seats', 'Price'],
            'rows' => [['Starter', 3, '$9'], ['Team', 25, '$49']],
            'footer' => [],
        ]);
});

it('builds the same table fluently, in any order', function () {
    $named = Table::make(['Plan', 'Seats', 'Price'], [['Starter', 3, '$9'], ['Team', 25, '$49']], title: 'Pricing changes');

    $fluent = Table::make()
        ->row(['Starter', 3, '$9'])
        ->title('Pricing changes')
        ->row(['Team', 25, '$49'])
        ->headers(['Plan', 'Seats', 'Price']);

    expect($fluent->toPayload())->toBe($named->toPayload())
        ->and(Table::make(rows: [['a']])->toPayload())->toBe(['$body' => 'Storyfeed/Body/Table', '$v' => 1, 'rows' => [['a']]]);
});

it('replaces with headers() and rows(), and appends with row() and footer()', function () {
    $table = Table::make(['Old'], [['gone']])
        ->headers(['Item', 'Qty', 'Price'])
        ->rows(collect([['Starter plan', 1, '$9.00']]))
        ->row(['Extra seats', 4, '$40.00'])
        ->footer(['Subtotal', '', '$49.00'])
        ->footer(['Total', '', '$49.00']);

    expect($table->toPayload())->toMatchArray([
        'headers' => ['Item', 'Qty', 'Price'],
        'rows' => [['Starter plan', 1, '$9.00'], ['Extra seats', 4, '$40.00']],
        'footer' => [['Subtotal', '', '$49.00'], ['Total', '', '$49.00']],
    ]);
});

it('supports when() for a conditional row', function () {
    $table = fn (bool $totals) => Table::make(rows: [['a', 1]])
        ->when($totals, fn (Table $t) => $t->footer(['Total', 1]));

    expect(rendered($table(true))['footer'])->toBe([['Total', 1]])
        ->and($table(false)->toPayload())->not->toHaveKey('footer');
});

it('pads ragged rows to the widest row instead of rejecting them', function () {
    expect(Table::make(rows: [['a'], ['b', 2, 3], []])->footer(['t', 1])->toPayload())->toMatchArray([
        'rows' => [['a', null, null], ['b', 2, 3], [null, null, null]],
        'footer' => [['t', 1, null]],
    ])
        ->and(Table::make(['A', 'B', 'C'], [['a']])->toPayload()['rows'])->toBe([['a', null, null]]);
});

it('throws, naming the row, when a row is wider than the headers', function () {
    expect(fn () => Table::make(['A', 'B'], [['a', 'b'], ['a', 'b', 'c']])->toPayload())
        ->toThrow(InvalidArgumentException::class, 'Table row 2 has 3 cells, but the table has 2 headers.')
        ->and(fn () => Table::make(['A'])->footer(['Total', '$9'])->toPayload())
        ->toThrow(InvalidArgumentException::class, 'Table footer row 1 has 2 cells, but the table has 1 headers.');
});

it('takes text, numbers, null and links as cells, and keeps line breaks', function () {
    $link = FeedLink::make();
    $table = Table::make(rows: [["14 Wyndham St\nGuelph", 1.5, null, $link, new HtmlString('<b>x</b>')]]);
    $link->label('Ana')->href('/people/ana');

    expect($table->toPayload()['rows'])->toBe([["14 Wyndham St\nGuelph", 1.5, null, ['label' => 'Ana', 'href' => '/people/ana'], '<b>x</b>']]);
});

it('refuses a cell that is not one, because bodies never nest', function (mixed $cell, string $type) {
    expect(fn () => Table::make(rows: [[$cell]])->toPayload())
        ->toThrow(InvalidArgumentException::class, "A Table cell is a string, int, float, null or FeedLink; {$type} given.");
})->with([
    'a body' => [Prose::make('x'), Prose::class],
    'a bool' => [true, 'bool'],
    'an array' => [[1], 'array'],
]);

it('falls back to its title, and to nothing without one', function () {
    expect(Table::make(rows: [['a']])->toPayload())->not->toHaveKey('$fallback')
        ->and(Table::make(rows: [['a']], title: 'Totals')->fallback('Invoice #12')->toPayload()['$fallback'])->toBe('Invoice #12');
});

it('upgrades anything stored into a table a renderer can draw', function () {
    foreach ([1, 0, 999] as $version) {
        expect(Table::upgrade([], $version))->toBe(['title' => null, 'headers' => [], 'rows' => [], 'footer' => []])
            ->and(Table::upgrade([
                'title' => 7,
                'headers' => ['A', 2, null],
                'rows' => [['a', ['label' => 'Ana', 'href' => null], ['x' => 1], true], 'junk', ['b']],
                'footer' => 'junk',
            ], $version))->toBe([
                'title' => null,
                'headers' => ['A', '2', ''],
                'rows' => [['a', ['label' => 'Ana', 'href' => null], null, null], ['b', null, null, null]],
                'footer' => [],
            ]);
    }
});
