<?php

use Storyfeed\Body\Change;
use Storyfeed\Body\Component;
use Storyfeed\Body\Excerpt;
use Storyfeed\Body\File;
use Storyfeed\Body\ItemList;
use Storyfeed\Body\KeyValue;
use Storyfeed\Body\MediaObject;
use Storyfeed\Body\Prose;
use Storyfeed\Exceptions\IncompleteFeedValue;
use Storyfeed\FeedLink;
use Storyfeed\FeedResource;
use Storyfeed\MediaSlot;

/*
 * Every body type core ships can be written two ways, and the two must be
 * indistinguishable once stored: a reader cannot tell which one wrote a row.
 */
dataset('fluent and named bodies', fn () => [
    'Change' => [
        fn () => Change::make()->field('Status', 'Draft', 'Ready')->changes(['Owner' => [1 => 'Ben']]),
        fn () => Change::make(['Status' => ['Draft', 'Ready'], 'Owner' => [1 => 'Ben']]),
    ],
    'Excerpt' => [
        fn () => Excerpt::make()->text('Fine by me.')->from('Jasper')->truncated(false),
        fn () => Excerpt::make('Fine by me.', from: 'Jasper', truncated: false),
    ],
    'File' => [
        fn () => File::make()->size(4200)->mediaType('application/zip')->name('archive.zip'),
        fn () => File::make(size: 4200, mediaType: 'application/zip', name: 'archive.zip'),
    ],
    'ItemList' => [
        fn () => ItemList::make()->items(['Rice', FeedLink::make()->label('Saffron')])->title('Pantry')->totalItems(9)->more(FeedLink::make('All nine', '/pantry')),
        fn () => ItemList::make(['Rice', FeedLink::make('Saffron')], title: 'Pantry', totalItems: 9, more: FeedLink::make('All nine', '/pantry')),
    ],
    'ItemList, ordered' => [
        fn () => ItemList::ordered()->items(['Soak', 'Simmer']),
        fn () => ItemList::ordered(['Soak', 'Simmer']),
    ],
    'KeyValue' => [
        fn () => KeyValue::make()->items(['Address' => KeyValue::verbatim('10.0.0.1')])->items('Seat', null)->title('Fetch')->missing('none'),
        fn () => KeyValue::make(['Address' => KeyValue::verbatim('10.0.0.1'), 'Seat' => null], title: 'Fetch', missing: 'none'),
    ],
    'Prose' => [
        fn () => Prose::make()->content('Basmati replaces Jasmine.')->title('Note'),
        fn () => Prose::make('Basmati replaces Jasmine.', title: 'Note'),
    ],
    'Prose, markdown' => [
        fn () => Prose::markdown()->content('**Basmati**'),
        fn () => Prose::markdown('**Basmati**'),
    ],
    'MediaObject' => [
        fn () => MediaObject::make()->subject(FeedLink::make()->label('N201'))->content('Basmati.')->image(MediaSlot::Preview)
            ->attachments(FeedResource::make()->href('/a.pdf'))->attachments([FeedResource::make('/b.pdf')])->footnote('Approved'),
        fn () => MediaObject::make(subject: FeedLink::make('N201'), content: 'Basmati.', image: MediaSlot::Preview, attachments: [FeedResource::make('/a.pdf'), FeedResource::make('/b.pdf')], footnote: 'Approved'),
    ],
    'Component' => [
        fn () => Component::make()->name('Common/ScoreCard')->props(['home' => 2])->props('away', 1),
        fn () => Component::make(name: 'Common/ScoreCard', props: ['home' => 2, 'away' => 1]),
    ],
]);

it('stores the same payload whether it was chained or named', function (Closure $fluent, Closure $named) {
    expect($fluent()->toPayload())->toBe($named()->toPayload());
})->with('fluent and named bodies');

it('changes the body it is called on, and returns it', function () {
    $excerpt = Excerpt::make();

    expect($excerpt->text('A'))->toBe($excerpt)
        ->and($excerpt->toPayload()['text'])->toBe('A');
});

it('reads a value set after the body was handed on, because it is read when used', function () {
    $link = FeedLink::make();
    $list = ItemList::make()->items([$link]);
    $link->label('Saffron');

    expect($list->toPayload()['items'])->toBe([['label' => 'Saffron', 'href' => null]]);
});

it('appends lists and merges maps', function () {
    expect(ItemList::make(['a'])->items(['b'])->items(['c'])->toPayload()['items'])->toBe(['a', 'b', 'c'])
        ->and(Change::make(['A' => [1, 2]])->field('B', 3, 4)->field('A', 5, 6)->toPayload()['items'])
        ->toBe(['A' => [5, 6], 'B' => [3, 4]])
        ->and(MediaObject::make()->attachments(FeedResource::make('/a'))->attachments(FeedResource::make('/b'), FeedResource::make('/c'))->toPayload()['attachments'])
        ->toHaveCount(3);

    // A map merges: a key already here keeps its place and takes the later
    // value. A list of explicit pairs appends, which is how a key repeats.
    $rows = KeyValue::make(['A' => 1, 'B' => 2])
        ->items(['A' => 3])
        ->items('C', 4)
        ->items([['key' => 'C', 'value' => 5]])
        ->toPayload()['items'];

    expect(array_column($rows, 'value', null))->toBe([3, 2, 4, 5])
        ->and(array_column($rows, 'key'))->toBe(['A', 'B', 'C', 'C']);
});

it('gives rows the default missing word unless they say their own', function () {
    $rows = KeyValue::make(['Seat' => null, 'Table' => KeyValue::missingAs(null, 'not seated')])
        ->missing('unknown')
        ->toPayload()['items'];

    expect(array_column($rows, 'missing'))->toBe(['unknown', 'not seated']);
});

it('keeps withIcon() and its siblings, which now change the object too', function () {
    $media = MediaObject::make();

    expect($media->withPreview())->toBe($media)
        ->and($media->toPayload()['image'])->toBe('preview')
        ->and(fn () => $media->withIcon())->toThrow(LogicException::class)
        ->and(MediaObject::make()->attachments(FeedResource::make('/a'))->withAttachments(FeedResource::make('/b'))->toPayload()['attachments'])
        ->toBe([FeedResource::make('/b')->toPayload()]);
});

it('supports when() and unless() on every body type', function () {
    $prose = Prose::make('Text')
        ->when(true, fn (Prose $prose) => $prose->title('Shown'))
        ->unless(true, fn (Prose $prose) => $prose->title('Hidden'));

    expect($prose->toPayload()['title'])->toBe('Shown')
        ->and(Component::make('Card')->when(false, fn ($c) => $c->props('x', 1))->toPayload()['props'])->toBe([]);

    foreach ([Change::class, Excerpt::class, File::class, ItemList::class, KeyValue::class, MediaObject::class, Prose::class, Component::class] as $class) {
        expect(method_exists($class, 'when') && method_exists($class, 'unless'))->toBeTrue();
    }
});

it('names the method to call when a required value was never set', function (Closure $use, string $message) {
    expect($use)->toThrow(IncompleteFeedValue::class, $message);
})->with([
    'Excerpt' => [fn () => Excerpt::make()->from('Jasper')->toPayload(), 'Excerpt has no text. Call ->text(…) on it, or pass text: to Excerpt::make().'],
    'Prose' => [fn () => Prose::markdown()->toPayload(), 'Prose has no content. Call ->content(…) on it, or pass content: to Prose::make().'],
    'Component' => [fn () => Component::make()->toPayload(), 'Component has no name. Call ->name(…) on it, or pass name: to Component::make().'],
]);

it('starts every body type empty', function () {
    expect(Change::make()->toPayload()['items'])->toBe([])
        ->and(File::make()->toPayload()['name'])->toBeNull()
        ->and(ItemList::make()->toPayload()['items'])->toBe([])
        ->and(KeyValue::make()->toPayload()['items'])->toBe([])
        ->and(MediaObject::make()->toPayload()['subject'])->toBeNull();
});
