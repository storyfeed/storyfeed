<?php

use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedBuilder;
use Storyfeed\FeedResult;
use Storyfeed\Sources\Entry;
use Storyfeed\Support\FeedItem;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

/*
 * A WIDGET SENDS EACH ENTITY ONCE TOO (#255, #253, 2026-11-15).
 *
 * The entity and media maps (#242, #246) reached only the paginators, and
 * only app-wide through `storyfeed.payload`. A dashboard widget that read
 * `->limit(8)->get()` sent every group child in full: 7 groups × 25 children
 * came to 477KB. Now a read chooses per call with inlineEntities() and
 * inlineMedia(), and page() returns get()'s items in the shape of a page,
 * maps included. get() itself is unchanged: always inline, as Payload v1
 * has always sent it.
 */

/** Six reviewers each review seven customers, one customer at a time: seven groups sharing every child's actor. */
function transportWidgetFeed(): void
{
    $reviewers = collect(range(1, 6))->map(fn (int $i) => User::create(['name' => "Widget reviewer {$i}", 'email' => "widget{$i}@example.test"]));
    $customers = collect(range(1, 7))->map(fn (int $i) => Customer::create(['name' => "Widget customer {$i}"]));

    foreach ($customers as $c => $customer) {
        foreach ($reviewers as $r => $reviewer) {
            Storyfeed::activity('review', $customer)->actor($reviewer)->publishedAt(now()->subHours(8 - $c)->addMinutes($r))->publish();
        }
    }
}

/** @return list<array<string, mixed>> every entity array in a node tree */
function transportEntities(mixed $value): array
{
    if (! is_array($value)) {
        return [];
    }

    $found = array_key_exists('entity_ref', $value) && array_key_exists('type', $value) ? [$value] : [];

    foreach ($value as $child) {
        array_push($found, ...transportEntities($child));
    }

    return $found;
}

/** @return array<string, mixed> */
function transportPayload(mixed $value): array
{
    return json_decode((string) json_encode($value), true);
}

it('sends each entity of a widget once, however many groups and children show it', function () {
    transportWidgetFeed();

    $inline = Storyfeed::feed()->limit(8)->get();
    $page = Storyfeed::feed()->limit(8)->inlineEntities(false)->page();
    $payload = transportPayload($page);

    expect(collect($payload['data'])->where('kind', 'group'))->toHaveCount(7)
        ->and(collect($payload['data'])->sum(fn (array $group) => count($group['children'])))->toBe(42);

    // In data, every entity is a stub; whole, each is sent once, in the map.
    $stubs = transportEntities($payload['data']);
    expect($stubs)->not->toBeEmpty();
    foreach ($stubs as $stub) {
        expect(array_keys($stub))->toBe(['type', 'id', 'entity_ref'])
            ->and($payload['entities'])->toHaveKey($stub['entity_ref']);
    }

    expect($payload['entities'])->toHaveCount(13)
        ->and(collect($payload['entities'])->pluck('label')->sort()->values()->all())
        ->toBe(collect(range(1, 7))->map(fn (int $i) => "Widget customer {$i}")
            ->merge(collect(range(1, 6))->map(fn (int $i) => "Widget reviewer {$i}"))->sort()->values()->all())
        ->and($payload['media'])->toBe([]);

    // Every stub resolves to exactly the entity get() sends inline.
    foreach (transportEntities(transportPayload($inline)) as $n => $entity) {
        expect($stubs[$n]['entity_ref'])->toBe($entity['entity_ref'])
            ->and($payload['entities'][$entity['entity_ref']])->toBe($entity);
    }

    expect(strlen((string) json_encode($page)))->toBeLessThan(intdiv(strlen((string) json_encode($inline)) * 2, 3));
});

it('leaves get() exactly as it was, whatever the read or the config asks', function () {
    transportWidgetFeed();

    $default = json_encode(Storyfeed::feed()->limit(8)->get());

    expect(json_encode(Storyfeed::feed()->limit(8)->inlineEntities(false)->inlineMedia(false)->get()))->toBe($default);

    config(['storyfeed.payload.inline_entities' => false, 'storyfeed.payload.inline_media' => false]);

    expect(json_encode(Storyfeed::feed()->limit(8)->get()))->toBe($default)
        ->and(json_encode(Storyfeed::feed()->limit(8)->inlineEntities(false)->get()))->toBe($default)
        ->and(substr($default, 0, 1))->toBe('[');
});

it('reads by default as get() does, in the shape of a page', function () {
    transportWidgetFeed();

    $page = Storyfeed::feed()->limit(8)->page();
    $json = json_encode($page);

    expect($page)->toBeInstanceOf(FeedResult::class)
        ->and(array_keys($page->toArray()))->toBe(['data', 'payload_version', 'sync_token', 'media', 'entities'])
        ->and($page->toArray()['payload_version'])->toBe(1)
        ->and($page->toJson())->toBe($json)
        ->and($json)->toContain('"media":[]', '"entities":[]')
        ->and(transportPayload($page)['data'])->toBe(transportPayload(Storyfeed::feed()->limit(8)->get()))
        ->and($page->entities())->toHaveCount(13)
        ->and($page->media())->toHaveCount(13)
        ->and($page)->toHaveCount(7)
        ->and($page->items())->toHaveCount(7);

    foreach ($page as $item) {
        expect($item)->toBeInstanceOf(FeedItem::class);
    }
});

it('follows the config when the read does not choose', function () {
    transportWidgetFeed();
    config(['storyfeed.payload.inline_entities' => false]);

    $payload = Storyfeed::feed()->limit(8)->page()->toArray();

    expect($payload['entities'])->toHaveCount(13)
        ->and(array_keys(transportEntities($payload['data'])[0]))->toBe(['type', 'id', 'entity_ref']);
});

it('moves link and media into the media map with inlineMedia(false)', function () {
    transportWidgetFeed();

    $payload = transportPayload(Storyfeed::feed()->limit(8)->inlineMedia(false)->page());

    expect($payload['entities'])->toBe([])
        ->and($payload['media'])->toHaveCount(13);

    foreach (transportEntities($payload['data']) as $entity) {
        expect($entity)->toHaveKey('label')
            ->not->toHaveKeys(['link', 'media'])
            ->and($payload['media'])->toHaveKey($entity['media_ref']);
    }

    // Stubbed entities carry their media in the entity map, so the media map stays empty.
    $stubbed = Storyfeed::feed()->limit(8)->inlineEntities(false)->inlineMedia(false)->page()->toArray();
    expect($stubbed['media'])->toBe([])
        ->and(collect($stubbed['entities'])->first())->toHaveKeys(['link', 'media']);
});

it('overrides the config for each paginator, both ways', function () {
    transportWidgetFeed();

    $stubbed = [
        Storyfeed::feed()->inlineEntities(false)->cursorPaginate(8)->toArray(),
        Storyfeed::feed()->inlineEntities(false)->simplePaginate(8)->toArray(),
    ];

    foreach ($stubbed as $payload) {
        expect($payload['entities'])->toHaveCount(13)
            ->and(array_keys(transportEntities($payload['data'])[0]))->toBe(['type', 'id', 'entity_ref']);
    }

    $stripped = Storyfeed::feed()->inlineMedia(false)->cursorPaginate(8)->toArray();
    expect($stripped['media'])->toHaveCount(13)
        ->and(transportEntities($stripped['data'])[0])->not->toHaveKey('media');

    config(['storyfeed.payload.inline_entities' => false, 'storyfeed.payload.inline_media' => false]);

    foreach ([Storyfeed::feed()->inlineEntities()->inlineMedia()->cursorPaginate(8), Storyfeed::feed()->inlineEntities()->inlineMedia()->simplePaginate(8)] as $paginator) {
        $payload = $paginator->toArray();

        expect($payload['entities'])->toBe([])
            ->and($payload['media'])->toBe([])
            ->and(transportEntities($payload['data'])[0])->toHaveKeys(['label', 'link', 'media']);
    }
});

it('pages a group\'s members with the read\'s choice', function () {
    transportWidgetFeed();

    $group = Storyfeed::feed()->limit(1)->get()->first();
    $payload = Storyfeed::feed()->inlineEntities(false)->members($group->id(), 3)->toArray();

    expect($payload['data'])->toHaveCount(3)
        ->and($payload['entities'])->not->toBe([])
        ->and(array_keys($payload['data'][0]['actor']))->toBe(['type', 'id', 'entity_ref']);
});

it('settles a paginator\'s maps when the page is read, not when it is serialized', function () {
    transportWidgetFeed();
    config(['storyfeed.payload.inline_entities' => false]);

    $paginator = Storyfeed::feed()->cursorPaginate(8);
    config(['storyfeed.payload.inline_entities' => true]);
    $payload = $paginator->toArray();

    // The stubs were cut under the first config, so their map travels with them.
    expect(array_keys(transportEntities($payload['data'])[0]))->toBe(['type', 'id', 'entity_ref'])
        ->and($payload['entities'])->toHaveCount(13);
});

it('takes the choice from a named feed', function () {
    transportWidgetFeed();
    Storyfeed::feeds(['widget' => fn (FeedBuilder $feed) => $feed->limit(8)->inlineEntities(false)]);

    $page = Storyfeed::feed('widget')->page()->toArray();
    $paged = Storyfeed::feed('widget')->cursorPaginate()->toArray();

    expect($page['entities'])->toHaveCount(13)
        ->and($paged['entities'])->toHaveCount(13)
        ->and(json_encode(Storyfeed::feed('widget')->get()))->toBe(json_encode(Storyfeed::feed()->limit(8)->get()));
});

it('reads a composed feed into a page', function () {
    $customer = Customer::create(['name' => 'Composed customer']);

    $page = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->action('review', $customer)->publishedAt(now()->subMinutes(2)))
        ->add(fn (Entry $entry) => $entry->action('review', $customer)->publishedAt(now()->subMinute()))
        ->inlineEntities(false)
        ->page()
        ->toArray();

    expect($page['data'])->toHaveCount(2)
        ->and($page['sync_token'])->toBeNull()
        ->and($page['entities'])->toHaveCount(1);
});
