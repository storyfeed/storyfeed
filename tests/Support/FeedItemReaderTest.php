<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Fluent;
use Storyfeed\Support\Entity;
use Storyfeed\Support\FeedItem;
use Storyfeed\Support\Headline;

/**
 * Storyfeed\Support\FeedItem reads one item of a feed page. These nodes are
 * written by hand in the shapes docs/payload.md promises, so the reader is
 * pinned to the contract rather than to today's presenter; ReadPath/
 * FeedItemReadPathTest reads real pages.
 */
function readerEntity(string $type, ?string $label, ?string $url = null, array $extra = []): array
{
    return [
        'type' => $type, 'id' => '1', 'label' => $label, 'url' => $url,
        'attributes' => [], 'modal' => false, 'data' => [], 'media' => null,
        'body' => null, 'tombstone' => null, ...$extra,
    ];
}

function readerActivity(array $overrides = []): array
{
    return [
        'kind' => 'activity',
        'id' => '01J1K2M3N4P5Q6R7S8T9V0W1X2',
        'verb' => 'confirm',
        'published_at' => '2026-08-10T14:03:22Z',
        'headline_template' => ':actor confirmed :object',
        'headline' => null,
        'glyph' => 'bi-truck',
        'glyph_intent' => 'success',
        'actor' => readerEntity('user', 'Dana', '/users/1'),
        'object' => readerEntity('delivery', 'Delivery #1042'),
        'target' => null, 'context' => null, 'origin' => null, 'result' => null, 'instrument' => null,
        'data' => ['source' => 'import'],
        'thread' => null,
        'tombstoned' => [],
        'redundant' => false,
        'missing_headline_template' => null,
        'missing_headline' => null,
        ...$overrides,
    ];
}

function readerGroup(array $overrides = []): array
{
    $roles = ['actors', 'objects', 'targets', 'contexts', 'origins', 'results', 'instruments'];

    return [
        'kind' => 'group',
        'id' => 'grp_1',
        'axis' => 'actors',
        'count' => 5,
        'verb' => 'upload',
        'published_at' => '2026-08-10T14:03:22Z',
        'headline_template' => ':actors uploaded :count files to :target',
        'headline' => null,
        'glyph' => 'bi-upload',
        'glyph_intent' => null,
        'actor' => null, 'object' => null,
        'target' => readerEntity('project', 'Onboarding Portal', '/projects/7'),
        'context' => null, 'origin' => null, 'result' => null, 'instrument' => null,
        'sample' => [
            ...array_fill_keys($roles, []),
            'actors' => [readerEntity('user', 'Ana'), readerEntity('user', 'Ben'), readerEntity('user', 'Cy')],
            'targets' => [readerEntity('project', 'Onboarding Portal', '/projects/7')],
        ],
        'distinct' => [...array_fill_keys($roles, 0), 'actors' => 5, 'objects' => 5, 'targets' => 1],
        'children' => [readerActivity(['id' => 'a']), readerActivity(['id' => 'b'])],
        'children_truncated' => true,
        'tombstoned' => [],
        'redundant' => false,
        'distinct_tombstoned' => array_fill_keys($roles, 0),
        ...$overrides,
    ];
}

it('reads an activity by named accessors', function () {
    $item = FeedItem::of(readerActivity());

    expect($item->kind())->toBe('activity')
        ->and($item->isActivity())->toBeTrue()
        ->and($item->isGroup())->toBeFalse()
        ->and($item->id())->toBe('01J1K2M3N4P5Q6R7S8T9V0W1X2')
        ->and($item->verb())->toBe('confirm')
        ->and($item->publishedAt())->toBeInstanceOf(CarbonImmutable::class)
        ->and($item->publishedAt()->toIso8601ZuluString())->toBe('2026-08-10T14:03:22Z')
        ->and($item->glyph())->toBe('bi-truck')
        ->and($item->intent())->toBe('success')
        ->and($item->count())->toBe(1)
        ->and($item->headline())->toBeInstanceOf(Headline::class)
        ->and($item->actor())->toBeInstanceOf(Entity::class)
        ->and($item->actor()->label())->toBe('Dana')
        ->and($item->actor()->role())->toBe('actor')
        ->and($item->object()->label())->toBe('Delivery #1042')
        ->and($item->target())->toBeNull()
        ->and($item->context())->toBeNull()
        ->and($item->origin())->toBeNull()
        ->and($item->result())->toBeNull()
        ->and($item->instrument())->toBeNull()
        ->and($item->children())->toBeEmpty()
        ->and($item->phrases())->toBeEmpty();
});

it('reads activity data and thread', function () {
    $item = FeedItem::of(readerActivity([
        'thread' => ['text' => 'Thursday?', 'by' => 'Nayani', 'kind' => 'asked', 'replies' => 3, 'truncated' => false],
    ]));

    expect($item->data())->toBeInstanceOf(Fluent::class)
        ->and($item->data()->string('source')->toString())->toBe('import')
        ->and($item->thread()->get('text'))->toBe('Thursday?')
        ->and($item->thread()->integer('replies'))->toBe(3);

    expect(FeedItem::of(readerActivity())->thread())->toBeNull();
});

it('reads an activity role as a one-or-none plural', function () {
    $item = FeedItem::of(readerActivity());

    expect($item->actors()->map->label()->all())->toBe(['Dana'])
        ->and($item->targets())->toBeEmpty()
        ->and($item->distinct('actor'))->toBe(1)
        ->and($item->distinct('targets'))->toBe(0);
});

it('reads a group: count, axis, samples, true totals and children', function () {
    $item = FeedItem::of(readerGroup());

    expect($item->isGroup())->toBeTrue()
        ->and($item->isDigest())->toBeFalse()
        ->and($item->axis())->toBe('actors')
        ->and($item->count())->toBe(5)
        ->and($item->actor())->toBeNull()
        ->and($item->target()->label())->toBe('Onboarding Portal')
        ->and($item->actors()->map->label()->all())->toBe(['Ana', 'Ben', 'Cy'])
        ->and($item->actors()->first()->role())->toBe('actor')
        ->and($item->entities('actors')->count())->toBe(3)
        ->and($item->distinct('actors'))->toBe(5)
        ->and($item->distinct('actor'))->toBe(5)
        ->and($item->objects())->toBeEmpty()
        ->and($item->distinct('objects'))->toBe(5)
        ->and($item->children())->toHaveCount(2)
        ->and($item->children()->first())->toBeInstanceOf(FeedItem::class)
        ->and($item->children()->map->id()->all())->toBe(['a', 'b'])
        ->and($item->childrenTruncated())->toBeTrue();
});

it('reads a digest row and its phrases', function () {
    $item = FeedItem::of(readerGroup([
        'axis' => 'summary',
        'period' => 'day',
        'verb' => null,
        'headline_template' => null,
        'actor' => readerEntity('user', 'Jasper Tey'),
        'phrases' => [
            ['verb' => 'ride', 'count' => 3, 'headline_template' => 'went on :count rides', 'headline' => null, 'glyph' => null, 'glyph_intent' => null, 'sample' => [], 'distinct' => []],
        ],
        'phrases_truncated' => false,
    ]));

    expect($item->isDigest())->toBeTrue()
        ->and($item->period())->toBe('day')
        ->and($item->verb())->toBeNull()
        ->and($item->phrases())->toHaveCount(1)
        ->and($item->phrases()->first()->verb())->toBe('ride')
        ->and($item->phrases()->first()->count())->toBe(3)
        ->and($item->phrases()->first()->kind())->toBeNull()
        ->and($item->phrases()->first()->publishedAt())->toBeNull()
        ->and($item->phrasesTruncated())->toBeFalse();
});

it('reads the tombstone fact', function () {
    $item = FeedItem::of(readerActivity([
        'tombstoned' => ['object'],
        'redundant' => true,
        'missing_headline_template' => 'An order :actor placed was later removed',
    ]));

    expect($item->tombstoned())->toBe(['object'])
        ->and($item->isRedundant())->toBeTrue()
        ->and($item->missingHeadline()->toString())->toBe('An order Dana placed was later removed')
        ->and(FeedItem::of(readerActivity())->missingHeadline())->toBeNull();
});

it('hands the payload back unchanged, and reads as the array it wraps', function () {
    $payload = readerGroup();
    $item = FeedItem::of($payload);

    expect($item->toArray())->toBe($payload)
        ->and(json_encode($item))->toBe(json_encode($payload))
        ->and($item['count'])->toBe(5)
        ->and($item['missing'])->toBeNull()
        ->and(isset($item['axis']))->toBeTrue()
        ->and($item->get('target.label'))->toBe('Onboarding Portal')
        ->and($item->get('nope', 'default'))->toBe('default')
        ->and(FeedItem::of($item)->toArray())->toBe($payload);
});

it('is read-only', function () {
    $item = FeedItem::of(readerActivity());

    expect(fn () => $item['verb'] = 'x')->toThrow(LogicException::class, 'FeedItem is read-only.');
    expect(function () use ($item) {
        unset($item['verb']);
    })->toThrow(LogicException::class);
});

it('is an error to call a method it does not declare', function () {
    expect(fn () => FeedItem::of(readerActivity())->label())->toThrow(BadMethodCallException::class);
});

it('reads a malformed item without throwing', function () {
    $item = FeedItem::of(['kind' => 'activity', 'actor' => 'not an entity', 'count' => 'many', 'sample' => ['actors' => 'x']]);

    expect($item->actor())->toBeNull()
        ->and($item->count())->toBe(1)
        ->and($item->actors())->toBeEmpty()
        ->and($item->publishedAt())->toBeNull()
        ->and($item->children())->toBeEmpty()
        ->and($item->tombstoned())->toBe([])
        ->and($item->headline()->toString())->toBe('Someone');
});
