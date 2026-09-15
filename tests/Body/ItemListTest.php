<?php

use Storyfeed\Body\ItemList;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\FeedLink;

it('serializes with the reserved keys, so a stored list describes itself', function () {
    $list = ItemList::make(['N101 Chicken Curry']);

    expect($list)->toBeInstanceOf(FeedBody::class)
        ->and($list->toPayload())->toBe([
            '$body' => 'Storyfeed/Body/ItemList',
            '$v' => 1,
            'title' => null,
            'ordered' => false,
            'items' => ['N101 Chicken Curry'],
            'totalItems' => null,
            'more' => null,
        ]);
});

it('keeps a string a string and a link a link, because they mean different things', function () {
    $items = ItemList::make([
        'N203 Chicken Kottu',
        FeedLink::make('N101 Chicken Curry', '/menu/1'),
    ])->toPayload()['items'];

    expect($items[0])->toBe('N203 Chicken Kottu')
        ->and($items[1])->toBe(['label' => 'N101 Chicken Curry', 'href' => '/menu/1']);
});

it('drops what is neither, rather than coercing it into a row nobody wrote', function () {
    $items = ItemList::make(['ok', 123, null, [], ['label' => '', 'href' => '/x']])->toPayload()['items'];

    expect($items)->toBe(['ok']);
});

it('rehydrates a stored link so a list survives a JSON round trip', function () {
    $items = ItemList::make([['label' => 'N302 Coconut Roti', 'href' => '/menu/6']])->toPayload()['items'];

    expect($items[0])->toBe(['label' => 'N302 Coconut Roti', 'href' => '/menu/6']);
});

it('says whether the sequence is part of what it means', function () {
    expect(ItemList::make(['a', 'b'])->toPayload()['ordered'])->toBeFalse()
        ->and(ItemList::ordered(['a', 'b'])->toPayload()['ordered'])->toBeTrue();
});

it('carries how many there are apart from how many were sent, and where the rest live', function () {
    $payload = ItemList::make(
        ['a', 'b'],
        title: 'Three dishes went out',
        totalItems: 12,
        more: FeedLink::make('See all', '/orders/1042/lines'),
    )->toPayload();

    expect($payload['title'])->toBe('Three dishes went out')
        ->and($payload['items'])->toHaveCount(2)
        ->and($payload['totalItems'])->toBe(12)
        ->and($payload['more'])->toBe(['label' => 'See all', 'href' => '/orders/1042/lines']);
});

it('renders an unknown version without throwing, because the row is stored either way', function () {
    foreach ([1, 0, 999] as $version) {
        expect(ItemList::upgrade(['items' => ['a', 'junk' => null], 'ordered' => true], $version))
            ->toBe(['title' => null, 'ordered' => true, 'items' => ['a'], 'totalItems' => null, 'more' => null]);

        expect(ItemList::upgrade([], $version))
            ->toBe(['title' => null, 'ordered' => false, 'items' => [], 'totalItems' => null, 'more' => null]);
    }
});

it('accepts anything that can say its own name, so a model need not be flattened first', function () {
    $dish = new class implements Stringable
    {
        public function __toString(): string
        {
            return 'N401 Mango Lassi';
        }
    };

    expect(ItemList::make([$dish])->toPayload()['items'])->toBe(['N401 Mango Lassi']);
});
