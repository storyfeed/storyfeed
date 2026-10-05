<?php

use Storyfeed\Facades\Story;
use Storyfeed\Facades\Storyfeed;

it('keys anonymous headlines on the type → verb ladder', function () {
    Story::verb('confirm')->anonymousHeadline('Confirmed');
    Story::verb('publish')->anonymousHeadline('Published');
    Story::verb('confirm')->override()->anonymousHeadline('Now confirmed');
    Story::for('order')->verb('confirm')->anonymousHeadline('Order confirmed');

    expect(Storyfeed::registeredActorlessGrammar())->toHaveKeys(['*.confirm', '*.publish', 'order.confirm'])
        ->and(Storyfeed::actorlessTemplate('order', 'confirm'))->toBe('Order confirmed')
        ->and(Storyfeed::actorlessTemplate('other', 'confirm'))->toBe('Now confirmed')
        ->and(Storyfeed::actorlessTemplate(null, 'confirm'))->toBe('Now confirmed')
        ->and(Storyfeed::actorlessTemplate('order', 'publish'))->toBe('Published')
        ->and(Storyfeed::actorlessTemplateKey('order', 'publish'))->toBe('*.publish')
        ->and(Storyfeed::actorlessTemplate('order', 'ship'))->toBeNull();

    Story::for('order')->fallback()->anonymousHeadline('Something happened to :object');
    Story::fallback()->anonymousHeadline('Something happened');
    expect(Storyfeed::actorlessTemplate('order', 'ship'))->toBe('Something happened to :object')
        ->and(Storyfeed::actorlessTemplate('invoice', 'ship'))->toBe('Something happened');
});

it('rejects actor tokens before changing the authored headline', function (string $template) {
    $definition = Story::verb('confirm')->anonymousHeadline('Confirmed');
    expect(fn () => $definition->anonymousHeadline($template))
        ->toThrow(InvalidArgumentException::class, 'must not contain :actor or :actors');
    expect(Storyfeed::actorlessTemplate(null, 'confirm'))->toBe('Confirmed');
})->with([':actor confirmed :object', ':actors confirmed :object']);
