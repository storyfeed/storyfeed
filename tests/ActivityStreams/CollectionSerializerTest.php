<?php

use Illuminate\Pagination\Cursor;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Models\Activity;
use Storyfeed\Serialization\CollectionSerializer;
use Workbench\App\Models\User;

/**
 * The AS2.0 OrderedCollection shape, exercised against the serializer directly.
 *
 * It used to be driven through `GET {prefix}/feed`, which was removed at
 * v0.8.0-alpha.2 for serving every published activity in the system with no
 * scope and no verb allowlist. The endpoint was the exposure; the shape is
 * roadmap work and stays covered — this file is where that coverage moved, and
 * it is the only reason the tests below look like unit tests rather than the
 * HTTP tests they were.
 *
 * The serializer now builds no query and owns no IRI. Both come from the
 * caller, which is what will let a named feed back this collection when the
 * endpoint returns.
 */
function page(?string $cursor = null, int $limit = 2)
{
    return Activity::query()
        ->published()
        ->orderBy('published_at', 'desc')
        ->orderBy('id', 'desc')
        ->cursorPaginate(perPage: $limit, cursor: $cursor === null ? null : Cursor::fromEncoded($cursor));
}

beforeEach(function () {
    $user = User::create(['name' => 'Sally', 'email' => 'sally@example.com']);

    foreach (range(1, 5) as $i) {
        Storyfeed::activity()->actor($user)->verb('ping')->publishedAt(now()->subMinutes($i))->publish();
    }

    $this->serializer = app(CollectionSerializer::class);
    $this->iri = 'https://example.test/storyfeed/feed';
});

it('serializes the root as an OrderedCollection at the IRI it was given', function () {
    $document = $this->serializer->collection(page(), $this->iri);

    expect($document['type'])->toBe('OrderedCollection')
        ->and($document['id'])->toBe($this->iri)
        ->and($document['orderedItems'])->toHaveCount(2)
        ->and($document['@context'][0])->toBe('https://www.w3.org/ns/activitystreams')
        ->and($document)->toHaveKey('next')
        // A COUNT over a large feed table on every request is a footgun.
        ->and($document)->not->toHaveKey('totalItems');
});

it('serializes a cursor page as an OrderedCollectionPage that points back at its root', function () {
    $first = $this->serializer->collection(page(), $this->iri);

    parse_str((string) parse_url($first['next'], PHP_URL_QUERY), $params);

    $second = $this->serializer->collection(page($params['cursor']), $this->iri, $params['cursor']);

    expect($second['type'])->toBe('OrderedCollectionPage')
        ->and($second['partOf'])->toBe($this->iri)
        ->and($second['id'])->toBe($this->iri.'?cursor='.$params['cursor'])
        ->and($second['orderedItems'])->toHaveCount(2);

    $firstIds = array_column($first['orderedItems'], 'id');
    $secondIds = array_column($second['orderedItems'], 'id');

    expect(array_intersect($firstIds, $secondIds))->toBe([]);
});

it('omits next on the last page', function () {
    $document = $this->serializer->collection(page(limit: 50), $this->iri);

    expect($document['orderedItems'])->toHaveCount(5)
        ->and($document)->not->toHaveKey('next');
});

it('serializes whatever activities it is handed, and queries for none of them', function () {
    // The guarantee the removal bought: this class cannot widen a caller's
    // scope, because it has no way to reach past what it was given.
    $one = Activity::query()->published()->orderBy('id')->cursorPaginate(perPage: 1);

    $document = $this->serializer->collection($one, $this->iri);

    expect($document['orderedItems'])->toHaveCount(1);
});
