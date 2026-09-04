<?php

use Storyfeed\ActivityStreams\Extension\ExtensionTerm;

it('carries its own prefix and matches the published extension context', function () {
    $document = json_decode(
        file_get_contents(__DIR__.'/../../resources/ns/context.jsonld'),
        true,
        flags: JSON_THROW_ON_ERROR,
    )['@context'];

    expect(ExtensionTerm::cases())->toBe([ExtensionTerm::Verb])
        ->and(ExtensionTerm::Verb->value)->toBe('sf:verb')
        ->and(ExtensionTerm::Verb->iri())->toBe('https://ns.storyfeed.dev#verb')
        ->and($document[ExtensionTerm::Verb->value]['@id'])->toBe(ExtensionTerm::Verb->value)
        ->and($document['sf'].'verb')->toBe(ExtensionTerm::Verb->iri());
});

it('resolves every supported extension spelling to a prefixed value', function (string $value) {
    expect(ExtensionTerm::tryFromLoose($value))->toBe(ExtensionTerm::Verb)
        ->and(ExtensionTerm::tryFromLoose($value)?->value)->toBe('sf:verb');
})->with([
    'verb', 'sf:verb', 'https://ns.storyfeed.dev#verb',
    'VERB', 'sf:VERB', 'https://ns.storyfeed.dev#VERB', '  sf:verb  ',
]);

it('leaves unknown extension terms for callers to preserve', function (string $value) {
    expect(ExtensionTerm::tryFromLoose($value))->toBeNull();
})->with([
    '', '   ', 'unknown', 'sf:unknown', 'https://ns.storyfeed.dev#unknown',
    'as:verb', 'https://www.w3.org/ns/activitystreams#verb',
    'ext:verb', 'https://example.com/#verb', 'sf:', 'sf:sf:verb',
]);
