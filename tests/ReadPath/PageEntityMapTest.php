<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\Payload\NodePresenter;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

/*
 * EACH ENTITY ONCE PER PAGE (#246, 2026-11-14).
 *
 * A 30-item log page was mostly the same actor, object and context sent on
 * every item. With `storyfeed.payload.inline_entities` off, a paginator sends
 * each entity as a `{type, id, entity_ref}` stub and carries it once, whole,
 * in a top-level `entities` map, so page weight grows with distinct entities
 * rather than items × roles. By default nothing changes but the additive
 * `entity_ref` and an empty map.
 */

/** Thirty activities by three people on five customers. */
function entityMapFeed(): void
{
    $people = collect(range(1, 3))->map(fn (int $i) => User::create(['name' => "Person {$i}", 'email' => "em{$i}@example.test"]));
    $customers = collect(range(1, 5))->map(fn (int $i) => Customer::create(['name' => "Customer {$i}"]));

    foreach (range(0, 29) as $i) {
        Storyfeed::activity('review', $customers[$i % 5])->actor($people[$i % 3])->publishedAt(now()->subMinutes(60 - $i))->publish();
    }
}

/** @return list<array<string, mixed>> every entity array in a node tree */
function entityMapEntities(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $found = array_key_exists('entity_ref', $value) && array_key_exists('type', $value) ? [$value] : [];

    foreach ($value as $child) {
        array_push($found, ...entityMapEntities($child));
    }

    return $found;
}

it('sends each entity once in the map and stubs every copy, shrinking the page', function () {
    entityMapFeed();

    $inline = Storyfeed::feed()->log()->cursorPaginate(30);
    $inlinePayload = $inline->toArray();
    $inlineBytes = strlen((string) json_encode($inline));

    config(['storyfeed.payload.inline_entities' => false]);
    $paginator = Storyfeed::feed()->log()->cursorPaginate(30);
    $payload = $paginator->toArray();
    $bytes = strlen((string) json_encode($paginator));

    $stubs = entityMapEntities($payload['data']);

    expect($payload['data'])->toHaveCount(30)
        ->and($stubs)->toHaveCount(60)
        ->and($payload['entities'])->toHaveCount(8)
        ->and($payload['media'])->toBe([])
        ->and($bytes)->toBeLessThan(intdiv($inlineBytes * 2, 3));

    // Every stub resolves to exactly the entity the default page sends inline.
    foreach (entityMapEntities($inlinePayload['data']) as $n => $entity) {
        expect($stubs[$n])->toBe(['type' => $entity['type'], 'id' => $entity['id'], 'entity_ref' => $entity['entity_ref']])
            ->and($payload['entities'][$entity['entity_ref']])->toBe($entity);
    }
});

it('stubs the entities a group carries in its roles, samples and children', function () {
    entityMapFeed();
    config(['storyfeed.payload.inline_entities' => false]);

    $payload = Storyfeed::feed()->cursorPaginate(30)->toArray();
    $group = collect($payload['data'])->firstWhere('kind', 'group');

    expect($group)->not->toBeNull()
        ->and(array_keys($group['sample']['actors'][0]))->toBe(['type', 'id', 'entity_ref'])
        ->and(array_keys($group['children'][0]['object']))->toBe(['type', 'id', 'entity_ref']);

    foreach (entityMapEntities($payload['data']) as $stub) {
        expect($payload['entities'])->toHaveKey($stub['entity_ref']);
    }
});

it('keeps link and media in the map entries whatever inline_media says', function () {
    entityMapFeed();
    config(['storyfeed.payload.inline_entities' => false, 'storyfeed.payload.inline_media' => false]);

    $payload = Storyfeed::feed()->log()->cursorPaginate(30)->toArray();

    expect($payload['media'])->toBe([]);
    foreach ($payload['entities'] as $entity) {
        expect($entity)->toHaveKeys(['link', 'media', 'media_ref', 'entity_ref']);
    }
});

it('sends an empty map and whole entities by default, and get() stays inline', function () {
    entityMapFeed();

    foreach ([Storyfeed::feed()->log()->cursorPaginate(30), Storyfeed::feed()->log()->simplePaginate(30)] as $paginator) {
        $payload = $paginator->toArray();

        expect($payload['entities'])->toBe([])
            ->and($paginator->entities())->toHaveCount(8)
            ->and(json_encode($payload))->toContain('"entities":[]');

        foreach (entityMapEntities($payload['data']) as $entity) {
            expect($entity)->toHaveKey('label')->and($entity['entity_ref'])->toBeString();
        }
    }

    config(['storyfeed.payload.inline_entities' => false]);
    expect(entityMapEntities(Storyfeed::feed()->log()->get()->toArray())[0])->toHaveKey('label');
});

it('names one ref per distinct entity, stable across pages', function () {
    entityMapFeed();

    $first = Storyfeed::feed()->log()->cursorPaginate(30)->entities();
    $again = Storyfeed::feed()->log()->cursorPaginate(30)->entities();

    expect(array_keys($again))->toBe(array_keys($first));
});

it('carries a null ref off a page', function () {
    $activity = Storyfeed::activity('review', Customer::create(['name' => 'Loose']))->publish();

    expect(app(NodePresenter::class)->activityNode($activity->fresh())['object'])->toHaveKey('entity_ref', null);
});
