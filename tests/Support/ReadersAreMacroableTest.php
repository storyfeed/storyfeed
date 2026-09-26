<?php

use Storyfeed\Support\Entity;
use Storyfeed\Support\FeedItem;
use Storyfeed\Support\Headline;

// The Support readers take Laravel's Macroable, as Uri and Str do: an app adds
// a method by registering it, and an unregistered method still throws.
afterEach(function () {
    FeedItem::flushMacros();
    Headline::flushMacros();
    Entity::flushMacros();
});

it('answers a registered macro', function () {
    FeedItem::macro('isPlacement', fn (): bool => $this->verb() === 'place');

    expect(FeedItem::of(['kind' => 'activity', 'verb' => 'place'])->isPlacement())->toBeTrue()
        ->and(FeedItem::of(['kind' => 'activity', 'verb' => 'ready'])->isPlacement())->toBeFalse();
});

it('still throws for a method nobody registered', function () {
    FeedItem::of(['kind' => 'activity', 'verb' => 'place'])->placed();
})->throws(BadMethodCallException::class);

it('makes every reader macroable', function (string $class) {
    expect(method_exists($class, 'macro'))->toBeTrue();
})->with([FeedItem::class, Headline::class, Entity::class]);
