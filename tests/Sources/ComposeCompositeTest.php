<?php

use Storyfeed\ComposedFeed;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\Grouping\Anchor;
use Storyfeed\Grouping\Group;
use Storyfeed\Serialization\ActivitySerializer;
use Storyfeed\Sources\Entry;
use Workbench\App\Models\Delivery;

/** @return list<Delivery> */
function composedFiles(): array
{
    return array_map(
        fn (int $i) => (new Delivery)->forceFill(['id' => $i, 'tracking_number' => "File-{$i}.pdf"]),
        [1, 2, 3],
    );
}

function campaignUpload(): Entry
{
    return (new Entry)
        ->by('Tomás')
        ->action('attach')
        ->objects(composedFiles())
        ->on(['type' => 'campaign', 'label' => 'Spring Campaign'])
        ->headline(':actor uploaded :count files to :target')
        ->publishedAt(now()->subHour());
}

function withCampaignUpload(): ComposedFeed
{
    return Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->by('Tomás')->action('post', ['type' => 'note', 'label' => 'Before'])->publishedAt(now()->subDay()))
        ->add(campaignUpload())
        ->add(fn (Entry $entry) => $entry->by('Tomás')->action('post', ['type' => 'note', 'label' => 'After'])->publishedAt(now()));
}

it('reads a composite entry as one composite group node in every read mode', function (Closure $read) {
    $items = $read(withCampaignUpload())->get()->toArray();

    expect(array_column($items, 'kind'))->toBe(['activity', 'group', 'activity'])
        ->and($items[1]['axis'])->toBe('composite')
        ->and(Anchor::fromEncoded($items[1]['id'])?->parameter('axis'))->toBe('composite')
        ->and($items[1]['count'])->toBe(3)
        ->and($items[1]['verb'])->toBe('attach')
        ->and($items[1]['headline_template'])->toBe(':actor uploaded :count files to :target')
        ->and($items[1]['actor']['label'])->toBe('Tomás')
        ->and($items[1]['target']['label'])->toBe('Spring Campaign')
        ->and($items[1]['object'] ?? null)->toBeNull()
        ->and($items[1]['distinct']['objects'])->toBe(3)
        ->and($items[1]['children'])->toHaveCount(3)
        // A member reads from the feed file, not as the composite's sentence.
        ->and(array_column($items[1]['children'], 'headline_template'))->toBe([null, null, null])
        ->and(array_column($items[1]['children'], 'verb'))->toBe(['attach', 'attach', 'attach']);
})->with([
    'log' => fn () => fn (ComposedFeed $feed) => $feed,
    'live' => fn () => fn (ComposedFeed $feed) => $feed->live(),
    'kept order' => fn () => fn (ComposedFeed $feed) => $feed->inOrder(),
    'grouped by context' => fn () => fn (ComposedFeed $feed) => $feed->groupBy(Group::byContext()),
]);

it('reads members in the feed\'s order: kept order as given, otherwise newest first, as a recorded composite\'s', function () {
    $dated = withCampaignUpload()->get()->toArray()[1];
    $kept = withCampaignUpload()->inOrder()->get()->toArray()[1];

    expect(array_column(array_column($dated['children'], 'object'), 'label'))->toBe(['File-3.pdf', 'File-2.pdf', 'File-1.pdf'])
        ->and(array_column(array_column($kept['children'], 'object'), 'label'))->toBe(['File-1.pdf', 'File-2.pdf', 'File-3.pdf']);
});

it('takes models, party names and entities as members, as any role does', function () {
    $items = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry
            ->by('Jasper')
            ->action('feature')
            ->objects([composedFiles()[0], 'Tey Labs', ['type' => 'package', 'label' => 'storyfeed/ui']]))
        ->inOrder()
        ->get()
        ->toArray();

    expect($items)->toHaveCount(1)
        ->and($items[0]['axis'])->toBe('composite')
        ->and(array_column(array_column($items[0]['children'], 'object'), 'type'))->toBe(['delivery', 'storyfeed.party', 'package'])
        ->and(array_column(array_column($items[0]['children'], 'object'), 'label'))->toContain('Tey Labs', 'storyfeed/ui');
});

it('never curates a composite\'s members into a Live group', function () {
    $items = withCampaignUpload()
        ->add(fn (Entry $entry) => $entry->by('Tomás')->action('attach', ['type' => 'file', 'label' => 'Solo-1.pdf'])->on(['type' => 'campaign', 'label' => 'Spring Campaign'])->publishedAt(now()->subMinutes(50)))
        ->add(fn (Entry $entry) => $entry->by('Tomás')->action('attach', ['type' => 'file', 'label' => 'Solo-2.pdf'])->on(['type' => 'campaign', 'label' => 'Spring Campaign'])->publishedAt(now()->subMinutes(40)))
        ->live()
        ->get()
        ->toArray();

    $composite = collect($items)->firstWhere('axis', 'composite');
    $repeat = collect($items)->firstWhere('axis', 'repeat');

    expect($composite['count'])->toBe(3)
        ->and($repeat['count'])->toBe(2)
        ->and(array_column(array_column($repeat['children'], 'object'), 'label'))->toBe(['Solo-2.pdf', 'Solo-1.pdf']);
});

it('stands at its entry\'s position in kept order, and pages past it once', function () {
    $feed = withCampaignUpload()->inOrder();

    $first = $feed->cursorPaginate(2);
    $rest = $feed->cursorPaginate(2, cursor: $first->nextCursor());

    expect(collect($first->items())->pluck('kind')->all())->toBe(['activity', 'group'])
        ->and(collect($rest->items())->pluck('object.label')->all())->toBe(['After'])
        ->and($rest->nextCursor())->toBeNull();
});

it('filters a composite\'s members as it filters entries', function () {
    $feed = withCampaignUpload();

    expect((clone $feed)->only('post')->get()->pluck('kind')->all())->toBe(['activity', 'activity'])
        ->and((clone $feed)->only('attach')->get()->toArray()[0]['count'])->toBe(3)
        // Narrowed to one member, the composite reads as that activity.
        ->and((clone $feed)->object(composedFiles()[1])->get()->toArray())->toHaveCount(1)
        ->and((clone $feed)->object(composedFiles()[1])->get()->toArray()[0]['kind'])->toBe('activity');
});

it('reads a composite\'s members past its children from the composed entries', function () {
    config()->set('storyfeed.grouping.children_limit', 2);

    $feed = withCampaignUpload();
    $group = $feed->get()->toArray()[1];

    $first = $feed->members($group['id'], 2);
    $rest = $feed->members($group['id'], 2, cursor: $first->nextCursor());

    expect($group['children_truncated'])->toBeTrue()
        ->and(collect($first->items())->pluck('object.label')->all())->toBe(['File-3.pdf', 'File-2.pdf'])
        ->and(collect($rest->items())->pluck('object.label')->all())->toBe(['File-1.pdf'])
        ->and(collect($rest->items())->pluck('kind')->all())->toBe(['activity']);
});

it('keeps a composite whole when the feed groups by context', function () {
    $release = ['type' => 'release', 'label' => 'v2.3'];

    $items = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->action('ship', ['type' => 'change', 'label' => 'One'])->context($release)->publishedAt(now()->subMinutes(3)))
        ->add(fn (Entry $entry) => $entry->action('ship', ['type' => 'change', 'label' => 'Two'])->context($release)->publishedAt(now()->subMinutes(2)))
        ->add(fn (Entry $entry) => $entry->action('attach')->objects(composedFiles())->context($release)->publishedAt(now()->subMinute()))
        ->groupBy(Group::byContext())
        ->get()
        ->toArray();

    expect(array_column($items, 'axis'))->toBe(['composite', 'context'])
        ->and(array_column($items, 'count'))->toBe([3, 2]);
});

it('serializes a composite entry\'s object as an OrderedCollection', function () {
    $activity = (new Entry)
        ->by('Tomás')
        ->action('attach')
        ->objects([['type' => 'file', 'label' => 'A.pdf'], ['type' => 'file', 'label' => 'B.pdf']])
        ->publishedAt(now())
        ->toActivity(1, 'campaign-upload');

    $document = app(ActivitySerializer::class)->activity($activity);

    expect($document['object']['type'])->toBe('OrderedCollection')
        ->and($document['object']['totalItems'])->toBe(2)
        ->and(array_column($document['object']['orderedItems'], 'name'))->toBe(['B.pdf', 'A.pdf']);
});

it('takes object() or objects(), never both', function (Closure $entry) {
    expect(fn () => $entry(new Entry))->toThrow(InvalidArgumentException::class, 'An entry takes object() OR objects(), not both.');
})->with([
    'object, then objects' => fn () => fn (Entry $entry) => $entry->action('attach', ['type' => 'file', 'label' => 'A'])->objects(composedFiles()),
    'objects, then object' => fn () => fn (Entry $entry) => $entry->objects(composedFiles())->object(['type' => 'file', 'label' => 'A']),
    'objects, then a headline replacement' => fn () => fn (Entry $entry) => $entry->objects(composedFiles())->headline(':object', ['object' => ['type' => 'file', 'label' => 'A']]),
]);

it('holds a composite\'s headline to what its members share', function () {
    expect(fn () => (new Entry)->action('attach')->objects(composedFiles())->headline(':actor uploaded :object')->by('Tomás')->validated())
        ->toThrow(InvalidArgumentException::class, 'names [:object], which a composite\'s members do not share')
        ->and(fn () => (new Entry)->objects([42]))
        ->toThrow(InvalidArgumentException::class, 'An object in objects() is a model, a party name or an entity; int given.')
        ->and((new Entry)->action('attach')->objects(composedFiles())->headline('Uploaded :objects (:count)')->validated())
        ->toBeInstanceOf(Entry::class);
});
