<?php

use Storyfeed\Body\Component;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\Exceptions\IncompleteFeedValue;

it('names an app component verbatim and carries its props, versioned', function () {
    $expected = [
        '$body' => 'Storyfeed/Body/Component',
        '$v' => 1,
        'name' => 'Common/ScoreCard',
        'props' => ['home' => 2, 'away' => 1],
    ];

    $fluent = Component::make()->name('Common/ScoreCard')->props(['home' => 2, 'away' => 1]);
    $named = Component::make(name: 'Common/ScoreCard', props: ['home' => 2, 'away' => 1]);

    expect($fluent)->toBeInstanceOf(FeedBody::class)
        ->and($fluent->toPayload())->toBe($expected)
        ->and($named->toPayload())->toBe($expected)
        ->and($fluent->toArray())->toBe($expected);
});

it('merges props as View::with() does, and sets one by key', function () {
    $payload = Component::make('Card', ['a' => 1, 'b' => 2])
        ->props(['b' => 3, 'c' => 4])
        ->props('d', 5)
        ->toPayload();

    expect($payload['props'])->toBe(['a' => 1, 'b' => 3, 'c' => 4, 'd' => 5]);
});

it('names the method to call when the component has no name', function () {
    Component::make()->props(['a' => 1])->toPayload();
})->throws(IncompleteFeedValue::class, 'Component has no name. Call ->name(…) on it, or pass name: to Component::make().');

it('upgrades any stored version into something renderable', function () {
    foreach ([1, 0, 999] as $version) {
        expect(Component::upgrade(['name' => 'Card', 'props' => ['a' => 1]], $version))->toBe(['name' => 'Card', 'props' => ['a' => 1]])
            ->and(Component::upgrade(['name' => 42, 'props' => 'x'], $version))->toBe(['name' => '', 'props' => []]);
    }
});
