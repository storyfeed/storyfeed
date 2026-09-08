<?php

use Storyfeed\Facades\Storyfeed;

it('merges overwrites and replaces actorless entries by exact verb', function () {
    Storyfeed::actorlessGrammar(['confirm' => 'Confirmed', 'publish' => 'Published']);
    Storyfeed::actorlessGrammar(['confirm' => 'Now confirmed', 'order.confirm' => 'Order confirmed']);
    expect(Storyfeed::actorlessTemplate('confirm'))->toBe('Now confirmed')
        ->and(Storyfeed::actorlessTemplate('publish'))->toBe('Published')
        ->and(Storyfeed::actorlessTemplate('order.confirm'))->toBe('Order confirmed')
        ->and(Storyfeed::actorlessTemplate('other.confirm'))->toBeNull();
    Storyfeed::actorlessGrammar(['confirm' => 'Replaced'], merge: false);
    expect(Storyfeed::actorlessTemplate('publish'))->toBeNull();
    Storyfeed::actorlessGrammar([], merge: false);
    expect(Storyfeed::actorlessTemplate('confirm'))->toBeNull();
});

it('rejects actor tokens before changing the registry', function (string $template) {
    Storyfeed::actorlessGrammar(['confirm' => 'Confirmed']);
    expect(fn () => Storyfeed::actorlessGrammar(['confirm' => 'Changed', 'publish' => $template]))
        ->toThrow(InvalidArgumentException::class, 'must not contain :actor or :actors');
    expect(Storyfeed::actorlessTemplate('confirm'))->toBe('Confirmed');
})->with([':actor confirmed :object', ':actors confirmed :object']);

it('rejects lists with a verb keyed registration example', function () {
    expect(fn () => Storyfeed::actorlessGrammar(['Confirmed']))
        ->toThrow(InvalidArgumentException::class, "Storyfeed::actorlessGrammar(['confirm' => ':object was confirmed']) — keys are exact verbs.");
});
