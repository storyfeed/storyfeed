<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Lang;
use Storyfeed\Support\Entity;
use Storyfeed\Support\FeedItem;

/**
 * Storyfeed\Support\Headline: the sentence in parts, as text and as HTML,
 * with the fallback words a renderer is told to use when the payload has
 * nothing to name. Node builders live in FeedItemReaderTest.php, which
 * Pest loads first; they are repeated here so this file runs alone.
 */
function headlineEntity(string $type, ?string $label, ?string $url = null, array $extra = []): array
{
    return [
        'type' => $type, 'id' => '1', 'label' => $label, 'url' => $url,
        'attributes' => [], 'modal' => false, 'data' => [], 'media' => null,
        'body' => null, 'tombstone' => null, ...$extra,
    ];
}

function headlineActivity(array $overrides = []): FeedItem
{
    return FeedItem::of([
        'kind' => 'activity', 'id' => 'a1', 'verb' => 'confirm', 'published_at' => '2026-08-10T14:03:22Z',
        'headline_template' => ':actor confirmed :object', 'headline' => null,
        'actor' => headlineEntity('user', 'Dana', '/users/1'),
        'object' => headlineEntity('delivery', 'Delivery #1042'),
        'target' => null, 'context' => null, 'origin' => null, 'result' => null, 'instrument' => null,
        ...$overrides,
    ]);
}

function headlineGroup(array $overrides = []): FeedItem
{
    $roles = ['actors', 'objects', 'targets', 'contexts', 'origins', 'results', 'instruments'];

    return FeedItem::of([
        'kind' => 'group', 'id' => 'g1', 'axis' => 'actors', 'count' => 5, 'verb' => 'upload',
        'published_at' => '2026-08-10T14:03:22Z',
        'headline_template' => ':actors uploaded :count files to :target', 'headline' => null,
        'actor' => null, 'object' => null,
        'target' => headlineEntity('project', 'Onboarding Portal', '/projects/7'),
        'context' => null, 'origin' => null, 'result' => null, 'instrument' => null,
        'sample' => [
            ...array_fill_keys($roles, []),
            'actors' => [headlineEntity('user', 'Ana', '/users/2'), headlineEntity('user', 'Ben'), headlineEntity('user', 'Cy')],
            'targets' => [headlineEntity('project', 'Onboarding Portal', '/projects/7')],
        ],
        'distinct' => [...array_fill_keys($roles, 0), 'actors' => 5, 'targets' => 1],
        'children' => [],
        ...$overrides,
    ]);
}

it('reads an activity template as text', function () {
    expect(headlineActivity()->headline()->toString())->toBe('Dana confirmed Delivery #1042')
        ->and((string) headlineActivity()->headline())->toBe('Dana confirmed Delivery #1042')
        ->and(json_encode(headlineActivity()->headline()))->toBe('"Dana confirmed Delivery #1042"')
        ->and(headlineActivity()->headline()->template())->toBe(':actor confirmed :object')
        ->and(headlineActivity()->headline()->isFallback())->toBeFalse();
});

it('reads an activity template in segments', function () {
    $segments = headlineActivity()->headline()->segments();

    expect($segments->pluck('type')->all())->toBe(['entity', 'text', 'entity'])
        ->and($segments->pluck('text')->all())->toBe(['Dana', ' confirmed ', 'Delivery #1042'])
        ->and($segments[0]['role'])->toBe('actor')
        ->and($segments[0]['entity'])->toBeInstanceOf(Entity::class)
        ->and($segments[2]['role'])->toBe('object');
});

it('draws entities as links, and escapes everything else', function () {
    $item = headlineActivity([
        'actor' => headlineEntity('user', 'Dana <3', '/users/1?a=1&b=2', ['attributes' => ['target' => '_blank']]),
        'headline_template' => ':actor confirmed <b>:object</b>',
    ]);

    expect($item->headline()->toHtml())
        ->toBe('<a href="/users/1?a=1&amp;b=2" target="_blank">Dana &lt;3</a> confirmed &lt;b&gt;Delivery #1042&lt;/b&gt;');
});

it('renders as HTML in Blade echoes, and as text when cast', function () {
    $html = Blade::render('{{ $item->headline() }}', ['item' => headlineActivity()]);

    expect($html)->toBe('<a href="/users/1">Dana</a> confirmed Delivery #1042');
});

it('takes a closure to draw entities your way', function () {
    $html = headlineActivity()->headline()->toHtml(fn (Entity $entity): string => '<strong>'.e($entity->toString()).'</strong>');

    expect($html)->toBe('<strong>Dana</strong> confirmed <strong>Delivery #1042</strong>');
});

it('reads finished text as one text segment', function () {
    $item = headlineActivity(['headline_template' => null, 'headline' => 'Dana & co. confirmed it']);

    expect($item->headline()->segments()->all())->toBe([['type' => 'text', 'text' => 'Dana & co. confirmed it']])
        ->and($item->headline()->toHtml())->toBe('Dana &amp; co. confirmed it')
        ->and($item->headline()->template())->toBeNull()
        ->and($item->headline()->isFallback())->toBeFalse();
});

it('reads a null actor as Someone, anonymous', function () {
    $item = headlineActivity(['actor' => null]);

    expect($item->headline()->toString())->toBe('Someone confirmed Delivery #1042')
        ->and($item->headline()->segments()[0]['entity'])->toBeNull()
        ->and($item->headline()->toHtml())->toBe('Someone confirmed Delivery #1042');
});

it('reads a degraded entity with a placeholder for its role', function () {
    $item = headlineActivity([
        'actor' => headlineEntity('user', null),
        'object' => headlineEntity('delivery', null),
    ]);

    expect($item->headline()->toString())->toBe('Someone confirmed Something');
});

it('reads a tombstone by its former type', function () {
    $tombstone = fn (string $formerType, ?string $label = null) => headlineEntity('storyfeed.tombstone', $label, null, [
        'tombstone' => ['formerType' => $formerType, 'deleted' => '2026-09-23T12:00:00.000000Z', 'approximate' => false, 'removedBy' => null],
    ]);

    expect(headlineActivity(['object' => $tombstone('line_item')])->headline()->toString())->toBe('Dana confirmed a removed line item')
        ->and(headlineActivity(['actor' => $tombstone('customer')])->headline()->toString())->toBe('a former customer confirmed Delivery #1042')
        ->and(headlineActivity(['object' => $tombstone('order', 'Order #7')])->headline()->toString())->toBe('Dana confirmed Order #7');
});

it('reads a group plural as the sample and how many more', function () {
    $headline = headlineGroup()->headline();

    expect($headline->toString())->toBe('Ana, Ben, Cy and 2 more uploaded 5 files to Onboarding Portal');

    $list = $headline->segments()->first();

    expect($list['type'])->toBe('entities')
        ->and($list['role'])->toBe('actor')
        ->and($list['total'])->toBe(5)
        ->and($list['entities']->map->label()->all())->toBe(['Ana', 'Ben', 'Cy']);

    expect($headline->toHtml())
        ->toBe('<a href="/users/2">Ana</a>, Ben, Cy and 2 more uploaded 5 files to <a href="/projects/7">Onboarding Portal</a>');
});

it('joins a full plural with and', function () {
    $item = headlineGroup(['distinct' => ['actors' => 3, 'targets' => 1]]);

    expect($item->headline()->toString())->toBe('Ana, Ben and Cy uploaded 5 files to Onboarding Portal');
});

it('never names one entity for a singular token over a group of many', function () {
    $item = headlineGroup(['headline_template' => ':actor uploaded files']);

    expect($item->headline()->toString())->toBe('Ana, Ben, Cy and 2 more uploaded files')
        ->and($item->headline()->segments()->first()['type'])->toBe('entities');
});

it('names the one entity a group holds for a singular token, pinned or not', function () {
    $one = headlineGroup([
        'headline_template' => ':actor uploaded :count files',
        'sample' => ['actors' => [headlineEntity('user', 'Ana')]],
        'distinct' => ['actors' => 1],
    ]);

    expect($one->headline()->toString())->toBe('Ana uploaded 5 files')
        ->and($one->headline()->segments()->first()['type'])->toBe('entity');
});

it('reads :others as the actors not sampled', function () {
    $item = headlineGroup(['headline_template' => ':actors and :others uploaded files']);

    expect($item->headline()->segments()[2]['text'])->toBe('2 others');
});

it('leaves unknown tokens as text', function () {
    $item = headlineActivity(['headline_template' => ':actor paid :amount for :object']);

    expect($item->headline()->toString())->toBe('Dana paid :amount for Delivery #1042');
});

it('reads a group with no headline as its count', function () {
    $item = headlineGroup(['headline_template' => null]);

    expect($item->headline()->toString())->toBe('5 activities')
        ->and($item->headline()->isFallback())->toBeTrue();
});

it('reads an activity with no headline from its actor, verb and object', function () {
    expect(headlineActivity(['headline_template' => null])->headline()->toString())->toBe('Dana confirm Delivery #1042')
        ->and(headlineActivity(['headline_template' => null, 'object' => null])->headline()->toString())->toBe('Dana confirm')
        ->and(headlineActivity(['headline_template' => null])->headline()->isFallback())->toBeTrue();
});

it('reads a digest row: the person once, then the phrases joined', function () {
    $phrase = fn (string $verb, int $count, ?string $template) => [
        'verb' => $verb, 'count' => $count, 'headline_template' => $template, 'headline' => null,
        'glyph' => null, 'glyph_intent' => null, 'sample' => ['targets' => [headlineEntity('customer', 'the Fun Fair')]], 'distinct' => ['targets' => 1],
    ];

    $item = headlineGroup([
        'axis' => 'summary', 'period' => 'day', 'verb' => null, 'count' => 7, 'headline_template' => null,
        'actor' => headlineEntity('user', 'Jasper Tey', '/users/9'),
        'phrases' => [
            $phrase('check_in', 1, 'checked in at :target'),
            $phrase('balloon', 1, 'got a balloon'),
            $phrase('ride', 3, null),
        ],
        'phrases_truncated' => true,
    ]);

    expect($item->headline()->toString())->toBe('Jasper Tey checked in at the Fun Fair, got a balloon, ride (3) and 2 more')
        ->and($item->headline()->isFallback())->toBeTrue()
        ->and($item->headline()->toHtml())->toStartWith('<a href="/users/9">Jasper Tey</a> checked in at the Fun Fair, ');
});

it('reads a crowd digest row by its people', function () {
    $item = headlineGroup([
        'axis' => 'summary', 'period' => 'day', 'verb' => null, 'count' => 5, 'headline_template' => null,
        'actor' => null,
        'phrases' => [['verb' => 'balloon', 'count' => 5, 'headline_template' => 'got a balloon', 'headline' => null, 'sample' => [], 'distinct' => []]],
    ]);

    expect($item->headline()->toString())->toBe('Ana, Ben, Cy and 2 more got a balloon');
});

it('translates its words in the current locale', function () {
    Lang::addLines(['feed.someone' => 'Quelqu’un', 'feed.activities' => '{1} :count activité|[2,*] :count activités'], 'fr', 'storyfeed');
    app()->setLocale('fr');

    expect(headlineActivity(['actor' => null])->headline()->toString())->toBe('Quelqu’un confirmed Delivery #1042')
        ->and(headlineGroup(['headline_template' => null])->headline()->toString())->toBe('5 activités');
});
