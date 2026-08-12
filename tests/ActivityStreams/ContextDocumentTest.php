<?php

use Storyfeed\ActivityStreams\Context;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Delivery;
use Workbench\App\Models\User;

/*
 * The context document served at Context::SF is a PUBLISHED CONTRACT: every
 * document the serializer emits references it by URL, and a JSON-LD processor
 * that cannot resolve a term has been handed an undefined property.
 *
 * The canonical copy lives in this repo (resources/ns/context.jsonld) rather
 * than only on the host, so it versions with the serializer that emits its
 * terms — and so the drift below is mechanically detectable instead of being
 * caught by a consumer.
 */

/** @return array<string, mixed> */
function contextDocument(): array
{
    $path = __DIR__.'/../../resources/ns/context.jsonld';

    expect($path)->toBeFile();

    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    return $decoded['@context'];
}

/**
 * Every `sf:`-prefixed key anywhere in a document, including nested entities.
 *
 * @param  array<array-key, mixed>  $document
 * @return list<string>
 */
function sfTerms(array $document): array
{
    $found = [];

    foreach ($document as $key => $value) {
        if (is_string($key) && str_starts_with($key, 'sf:')) {
            $found[] = $key;
        }

        if (is_array($value)) {
            $found = [...$found, ...sfTerms($value)];
        }
    }

    return array_values(array_unique($found));
}

it('defines the sf prefix at the published terms base', function () {
    expect(contextDocument()['sf'])->toBe(Context::SF_TERMS);
});

it('defines every sf: term the serializer emits', function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);
    $delivery = Delivery::create(['tracking_number' => 'TN-1']);

    $activity = Storyfeed::activity('confirm', $delivery)->actor($user)->publish();

    $emitted = sfTerms(serialize_one($activity));
    $defined = array_keys(contextDocument());

    // Not a vacuous pass: if the serializer ever stops emitting sf: terms,
    // this assertion would hold over an empty set and prove nothing.
    expect($emitted)->not->toBeEmpty()
        ->and(array_diff($emitted, $defined))->toBe([]);
});

it('serves terms that resolve under the extension host', function () {
    // A term base pointing anywhere but Context::SF would publish terms the
    // document at that URL does not define.
    expect(Context::SF_TERMS)->toStartWith(Context::SF);
});
