<?php

use Storyfeed\Body\CallToAction;
use Storyfeed\Exceptions\IncompleteFeedValue;
use Storyfeed\FeedBody;
use Storyfeed\FeedLink;

it('writes a heading, a sentence and one action whose link carries no label', function () {
    $body = CallToAction::make(subject: 'The countdown to 1.0', content: 'Five milestones to a stable release.')
        ->action('See the roadmap', FeedLink::to('https://storyfeed.dev/roadmap')->modal());

    expect($body)->toBeInstanceOf(FeedBody::class)
        ->and($body->toPayload())->toBe([
            '$body' => 'Storyfeed/Body/CallToAction',
            '$v' => 1,
            '$fallback' => 'The countdown to 1.0',
            'subject' => 'The countdown to 1.0',
            'content' => 'Five milestones to a stable release.',
            'action' => [
                'label' => 'See the roadmap',
                'link' => ['href' => 'https://storyfeed.dev/roadmap', 'modal' => true, 'attributes' => []],
            ],
        ]);
});

it('is a lone action at its smallest, and takes a string as a plain link', function () {
    $body = CallToAction::make()->action('Open the release', '/releases/0.17');

    expect($body->toPayload())->toBe([
        '$body' => 'Storyfeed/Body/CallToAction',
        '$v' => 1,
        '$fallback' => 'Open the release',
        'action' => ['label' => 'Open the release', 'link' => ['href' => '/releases/0.17', 'modal' => false, 'attributes' => []]],
    ])->and(rendered($body))->toBe([
        'subject' => null,
        'content' => null,
        'action' => ['label' => 'Open the release', 'link' => ['href' => '/releases/0.17', 'modal' => false, 'attributes' => []]],
    ]);
});

it('goes to the entity it belongs to through FeedLink::toEntity()', function () {
    $body = CallToAction::make()->action('Read the notes', FeedLink::toEntity()->attributes(['target' => '_blank']));

    expect(rendered($body)['action']['link'])->toBe(['href' => null, 'modal' => false, 'attributes' => ['target' => '_blank']]);
});

it('requires the action, naming the method', function () {
    expect(fn () => CallToAction::make(subject: 'The countdown')->toPayload())
        ->toThrow(IncompleteFeedValue::class, 'CallToAction has no action. Call ->action($text, $link) on it.')
        ->and(fn () => CallToAction::make()->action('Go', FeedLink::make('A place'))->toPayload())
        ->toThrow(IncompleteFeedValue::class, 'FeedLink has no href.');
});

it('builds the same body chained or named', function () {
    expect(CallToAction::make()->subject('A')->content('B')->action('Go', '/x')->toPayload())
        ->toBe(CallToAction::make(subject: 'A', content: 'B')->action('Go', '/x')->toPayload());
});

it('upgrades a malformed row to its text and no action, never an error', function () {
    expect(CallToAction::upgrade(['subject' => 'A', 'action' => ['label' => 'Go']], 1))
        ->toBe(['subject' => 'A', 'content' => null, 'action' => null])
        ->and(CallToAction::upgrade(['action' => ['label' => '', 'link' => ['href' => '/x']]], 1)['action'])->toBeNull()
        ->and(CallToAction::upgrade([], 1))->toBe(['subject' => null, 'content' => null, 'action' => null]);
});
