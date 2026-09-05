<?php

use Storyfeed\ActivityStreams\Property;
use Storyfeed\ActivityStreams\VocabularyTerm;

it('models only the AS2 properties currently emitted by the activity serializer', function () {
    expect(array_column(Property::cases(), 'value'))->toBe([
        'actor', 'object', 'target', 'context', 'published',
        'totalItems', 'orderedItems', 'name', 'url',
        'icon', 'image', 'preview', 'href', 'mediaType', 'width', 'height',
    ]);
});

it('builds property IRIs and resolves every supported spelling', function (Property $property) {
    $value = $property->value;
    $iri = 'https://www.w3.org/ns/activitystreams#'.($property === Property::OrderedItems ? 'items' : $value);

    expect($property)->toBeInstanceOf(VocabularyTerm::class)
        ->and($property->iri())->toBe($iri);

    foreach ([$value, 'as:'.$value, $iri, strtoupper($value), 'as:'.strtoupper($value), '  '.$value.'  '] as $form) {
        expect(Property::tryFromLoose($form))->toBe($property);
    }
})->with(Property::cases());

it('keeps the ordered items wire alias while resolving its defined AS2 IRI', function () {
    expect(Property::OrderedItems->value)->toBe('orderedItems')
        ->and(Property::OrderedItems->iri())->toBe('https://www.w3.org/ns/activitystreams#items');

    foreach (['items', 'as:items', 'https://www.w3.org/ns/activitystreams#items', 'orderedItems', 'as:orderedItems'] as $form) {
        expect(Property::tryFromLoose($form))->toBe(Property::OrderedItems);
    }
});

it('leaves unknown properties for callers to preserve', function (string $value) {
    expect(Property::tryFromLoose($value))->toBeNull();
})->with([
    '', '   ', 'unknown', 'as:unknown',
    'https://www.w3.org/ns/activitystreams#unknown',
    'sf:verb', 'ext:actor', 'https://example.com/#actor',
    'id', 'type', '@context', 'attachment', 'hreflang', 'rel', 'duration',
]);
