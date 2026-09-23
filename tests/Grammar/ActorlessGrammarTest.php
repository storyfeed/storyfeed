<?php

use Storyfeed\Facades\Storyfeed;

it('keys actorless entries on the type → verb ladder, a bare verb meaning *.verb', function () {
    Storyfeed::actorlessGrammar(['confirm' => 'Confirmed', 'publish' => 'Published']);
    Storyfeed::actorlessGrammar(['confirm' => 'Now confirmed', 'order.confirm' => 'Order confirmed']);

    expect(Storyfeed::registeredActorlessGrammar())->toHaveKeys(['*.confirm', '*.publish', 'order.confirm'])
        ->and(Storyfeed::actorlessTemplate('order', 'confirm'))->toBe('Order confirmed')
        ->and(Storyfeed::actorlessTemplate('other', 'confirm'))->toBe('Now confirmed')
        ->and(Storyfeed::actorlessTemplate(null, 'confirm'))->toBe('Now confirmed')
        ->and(Storyfeed::actorlessTemplate('order', 'publish'))->toBe('Published')
        ->and(Storyfeed::actorlessTemplateKey('order', 'publish'))->toBe('*.publish')
        ->and(Storyfeed::actorlessTemplate('order', 'ship'))->toBeNull();

    Storyfeed::actorlessGrammar(['order.*' => 'Something happened to :object', '*.*' => 'Something happened']);
    expect(Storyfeed::actorlessTemplate('order', 'ship'))->toBe('Something happened to :object')
        ->and(Storyfeed::actorlessTemplate('invoice', 'ship'))->toBe('Something happened');

    Storyfeed::actorlessGrammar(['confirm' => 'Replaced'], merge: false);
    expect(Storyfeed::actorlessTemplate(null, 'publish'))->toBeNull()
        ->and(Storyfeed::actorlessTemplate(null, 'confirm'))->toBe('Replaced');

    Storyfeed::actorlessGrammar([], merge: false);
    expect(Storyfeed::actorlessTemplate(null, 'confirm'))->toBeNull();
});

it('rejects actor tokens before changing the registry', function (string $template) {
    Storyfeed::actorlessGrammar(['confirm' => 'Confirmed']);
    expect(fn () => Storyfeed::actorlessGrammar(['confirm' => 'Changed', 'publish' => $template]))
        ->toThrow(InvalidArgumentException::class, 'must not contain :actor or :actors');
    expect(Storyfeed::actorlessTemplate(null, 'confirm'))->toBe('Confirmed');
})->with([':actor confirmed :object', ':actors confirmed :object']);

it('rejects lists with a type.verb keyed registration example', function () {
    expect(fn () => Storyfeed::actorlessGrammar(['Confirmed']))
        ->toThrow(InvalidArgumentException::class, "Storyfeed::actorlessGrammar(['order.confirm' => ':object was confirmed']) — keys are patterns");
});
