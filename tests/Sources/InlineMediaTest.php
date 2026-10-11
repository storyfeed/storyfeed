<?php

use Illuminate\Support\Facades\DB;
use Storyfeed\Body\CallToAction;
use Storyfeed\Body\Component;
use Storyfeed\Body\Excerpt;
use Storyfeed\Body\FileAttachment;
use Storyfeed\Body\Image;
use Storyfeed\Body\ItemList;
use Storyfeed\Body\KeyValue;
use Storyfeed\Body\MediaObject;
use Storyfeed\Body\Prose;
use Storyfeed\Body\Table;
use Storyfeed\DeferredMedia;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedImage;
use Storyfeed\MediaSlot;
use Storyfeed\Models\Activity;
use Storyfeed\Sources\ArraySource;
use Storyfeed\Sources\Entry;
use Storyfeed\Support\InlineEntity;

it('draws an inline actor\'s picture avatar from its media', function () {
    $items = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry
            ->by(['type' => 'product', 'label' => 'InvoiceJam', 'media' => ['icon' => 'https://teylabs.com/marks/invoicejam.svg']])
            ->headline(':actor sends invoices'))
        ->get()
        ->toArray();

    expect($items[0]['actor']['media'])->toBe([
        'icon' => ['src' => 'https://teylabs.com/marks/invoicejam.svg', 'mediaType' => null, 'width' => null, 'height' => null, 'alt' => null],
        'image' => null,
        'preview' => null,
        'initials' => null,
        'color' => null,
        'files' => [],
        'slots' => [],
    ]);
});

it('reads inline media in the payload shape a model\'s feedMedia() gives', function () {
    $media = [
        'icon' => FeedImage::make()->src('https://example.com/icon.png')->alt('Mark')->width(32)->height(32),
        'image' => ['src' => 'https://example.com/hero.jpg', 'alt' => 'The hero', 'width' => 1200, 'height' => 630, 'mediaType' => 'image/jpeg'],
        'preview' => 'https://example.com/thumb.jpg',
    ];

    $object = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->by('Tey Labs')->action('ship', ['type' => 'product', 'label' => 'Storyfeed', 'media' => $media]))
        ->get()
        ->toArray()[0]['object'];

    expect($object['media']['icon'])->toBe(['src' => 'https://example.com/icon.png', 'mediaType' => null, 'width' => 32, 'height' => 32, 'alt' => 'Mark'])
        ->and($object['media']['image'])->toBe(['src' => 'https://example.com/hero.jpg', 'mediaType' => 'image/jpeg', 'width' => 1200, 'height' => 630, 'alt' => 'The hero'])
        ->and($object['media']['preview'])->toBe(['src' => 'https://example.com/thumb.jpg', 'mediaType' => null, 'width' => null, 'height' => null, 'alt' => null])
        ->and($object['media']['initials'])->toBeNull();
});

it('takes avatar initials and colour from inline media', function () {
    $actor = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->by(['type' => 'team', 'label' => 'Kitchen', 'media' => ['initials' => 'KT', 'color' => '#4A9']])->headline(':actor'))
        ->get()
        ->toArray()[0]['actor'];

    expect($actor['media'])->toMatchArray(['icon' => null, 'initials' => 'KT', 'color' => '#44aa99']);
});

it('reads inline media from an array source', function () {
    $items = Storyfeed::feed()->source(new ArraySource([
        ['verb' => 'release', 'published_at' => '2026-10-01', 'actor' => ['type' => 'product', 'label' => 'Storyfeed', 'media' => ['icon' => 'https://storyfeed.dev/mark.svg']]],
    ]))->get()->toArray();

    expect($items[0]['actor']['media']['icon']['src'])->toBe('https://storyfeed.dev/mark.svg');
});

it('stores inline media on the write path and reads it back', function () {
    Storyfeed::activity('release')
        ->by(['type' => 'product', 'label' => 'Storyfeed', 'id' => 'storyfeed', 'media' => ['icon' => ['src' => 'https://storyfeed.dev/mark.svg', 'alt' => 'Storyfeed']]])
        ->publish();

    expect(Activity::query()->sole()->entities['actor']['media']['icon']['src'])->toBe('https://storyfeed.dev/mark.svg')
        ->and(Storyfeed::feed()->get()->toArray()[0]['actor']['media']['icon'])->toMatchArray(['src' => 'https://storyfeed.dev/mark.svg', 'alt' => 'Storyfeed']);
});

it('refuses inline media it cannot read', function (mixed $media, string $message) {
    expect(fn () => InlineEntity::assert('actor', ['type' => 'product', 'label' => 'Storyfeed', 'media' => $media]))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'not an array' => ['https://storyfeed.dev/mark.svg', "The [actor] entity's [media] must be an array of icon, image, preview, initials, color; string given."],
    'an unknown slot' => [['avatar' => 'https://storyfeed.dev/mark.svg'], "Unknown key [avatar] in the [actor] entity's [media]. It takes: icon, image, preview, initials, color."],
    'an image with no src' => [['icon' => ['alt' => 'Storyfeed']], "The [actor] entity's [media.icon] must be a URL, a FeedImage, or an array with a [src]"],
    'an image with an unknown key' => [['icon' => ['src' => 'https://storyfeed.dev/mark.svg', 'href' => 'x']], "The [actor] entity's [media.icon] must be a URL"],
    'initials that are not text' => [['initials' => 7], "The [actor] entity's [media.initials] must be a string."],
]);

it('refuses a slot-form body on an inline entity that declares no picture there', function () {
    $product = ['type' => 'product', 'label' => 'Storyfeed'];

    expect(fn () => Storyfeed::compose()->add(fn (Entry $entry) => $entry->action('ship', $product)->body(Image::make())))
        ->toThrow(InvalidArgumentException::class, "A Image body on the [object] entity shows its [preview] picture, but the entity has no model behind it and declares none, so it would draw nothing. Declare it on the entity (['media' => ['preview' => \$url]]), or give the body its own picture (FeedImage::make()->src(\$url)).")
        ->and(fn () => Storyfeed::compose()->add(fn (Entry $entry) => $entry->action('ship', $product)->body(MediaObject::make(subject: 'Storyfeed')->image(MediaSlot::Icon))))
        ->toThrow(InvalidArgumentException::class, 'A MediaObject body on the [object] entity shows its [icon] picture')
        ->and(fn () => Storyfeed::compose()->add(fn (Entry $entry) => $entry->by([...$product, 'body' => Image::make(MediaSlot::Image)])))
        ->toThrow(InvalidArgumentException::class, 'A Image body on the [actor] entity shows its [image] picture')
        ->and(fn () => Storyfeed::compose()->add(fn (Entry $entry) => $entry->action('ship', [...$product, 'media' => ['icon' => 'https://storyfeed.dev/mark.svg']])->body(Image::make())))
        ->toThrow(InvalidArgumentException::class, 'shows its [preview] picture')
        ->and(fn () => Storyfeed::compose()->add(fn (Entry $entry) => $entry->action('ship', $product)->body(Image::make(DeferredMedia::slot('sparkline')))))
        ->toThrow(InvalidArgumentException::class, 'shows its [slots.sparkline] picture, but the entity has no model behind it and only declares icon, image and preview')
        ->and(fn () => Storyfeed::feed()->source(new ArraySource([
            ['verb' => 'ship', 'published_at' => 'now', 'object' => $product, 'body' => Image::make()],
        ]))->get())
        ->toThrow(InvalidArgumentException::class, 'shows its [preview] picture');
});

it('refuses a slot-form body on a party name, which declares no pictures', function () {
    expect(fn () => Storyfeed::compose()->add(fn (Entry $entry) => $entry->action('ship', 'Storyfeed')->body(Image::make())))
        ->toThrow(InvalidArgumentException::class, "A Image body on the [object] party [Storyfeed] shows its [preview] picture, but a party name declares no pictures, so it would draw nothing. Pass an entity array that declares it (['type' => …, 'label' => 'Storyfeed', 'media' => ['preview' => \$url]]), or give the body its own picture (FeedImage::make()->src(\$url)).")
        ->and(fn () => Storyfeed::compose()->add(fn (Entry $entry) => $entry->action('ship', 'Storyfeed')->body(MediaObject::make(subject: 'Storyfeed')->image(MediaSlot::Icon))))
        ->toThrow(InvalidArgumentException::class, 'A MediaObject body on the [object] party [Storyfeed] shows its [icon] picture')
        ->and(fn () => Storyfeed::feed()->source(new ArraySource([
            ['verb' => 'ship', 'published_at' => 'now', 'object' => 'Storyfeed', 'body' => Image::make()],
        ]))->get())
        ->toThrow(InvalidArgumentException::class, 'party [Storyfeed] shows its [preview] picture');
});

it('keeps a body with its own picture on a party name', function () {
    $object = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->action('ship', 'Storyfeed')->body(Image::make('https://storyfeed.dev/card.png')))
        ->get()
        ->toArray()[0]['object'];

    expect($object['body'][0]['src'])->toBe('https://storyfeed.dev/card.png');
});

it('keeps a slot-form body on an inline entity that declares the picture', function () {
    $object = Storyfeed::compose()
        ->add(fn (Entry $entry) => $entry->action('ship', ['type' => 'product', 'label' => 'Storyfeed', 'media' => ['preview' => 'https://storyfeed.dev/card.png']])->body(Image::make()))
        ->get()
        ->toArray()[0]['object'];

    expect($object['body'][0]['image'])->toBe('preview')
        ->and($object['media']['preview']['src'])->toBe('https://storyfeed.dev/card.png');
});

it('composes every body type with no model and no tables anywhere', function () {
    config()->set('database.connections.bare', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('database.default', 'bare');

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    $product = fn (string $label, array $media = []) => [
        'type' => 'product', 'label' => $label, 'url' => 'https://teylabs.com/'.strtolower($label),
        'media' => ['icon' => "https://teylabs.com/marks/{$label}.svg", ...$media],
    ];
    $card = FeedImage::make()->src('https://teylabs.com/cards/storyfeed.png')->alt('Storyfeed')->width(1200)->height(630);

    $bodies = [
        'CallToAction' => CallToAction::make(subject: 'Try it', content: 'Install from Packagist.')->action('Install', 'https://packagist.org/packages/storyfeed/storyfeed'),
        'Component' => Component::make('product-card', ['slug' => 'storyfeed']),
        'Excerpt' => Excerpt::make('Activity feeds for Laravel, from the events you already have.'),
        'FileAttachment' => FileAttachment::make(size: 2048, mediaType: 'application/pdf', name: 'brochure.pdf'),
        'Image' => Image::make($card),
        'Image (slot)' => Image::make(MediaSlot::Preview),
        'ItemList' => ItemList::make(['Log', 'Live']),
        'KeyValue' => KeyValue::make(['License' => 'MIT']),
        'MediaObject' => MediaObject::make(subject: 'Storyfeed', content: 'Activity feeds for Laravel', image: $card),
        'MediaObject (slot)' => MediaObject::make(subject: 'Storyfeed', image: MediaSlot::Image),
        'Prose' => Prose::make('A story for every activity.'),
        'Table' => Table::make(['Plan', 'Price'], [['Core', 'Free']]),
    ];

    $feed = Storyfeed::compose()->inOrder();

    foreach ($bodies as $name => $body) {
        $feed->add(fn (Entry $entry) => $entry
            ->by($product('TeyLabs'))
            ->action('show', $product('Storyfeed', [
                'image' => 'https://teylabs.com/hero/storyfeed.jpg',
                'preview' => 'https://teylabs.com/thumb/storyfeed.jpg',
            ]))
            ->body($body)
            ->id($name));
    }

    $items = $feed->get()->toArray();

    expect($queries)->toBe(0)
        ->and(array_column($items, 'id'))->toBe(array_keys($bodies))
        ->and(array_unique(array_map(fn (array $item) => $item['object']['body'][0]['$body'], $items)))->toHaveCount(10);

    foreach ($items as $index => $item) {
        $body = array_values($bodies)[$index];

        expect($item['actor']['media']['icon']['src'])->toBe('https://teylabs.com/marks/TeyLabs.svg')
            ->and($item['object']['label'])->toBe('Storyfeed')
            ->and($item['object']['link']['href'])->toBe('https://teylabs.com/storyfeed')
            ->and($item['object']['media']['image']['src'])->toBe('https://teylabs.com/hero/storyfeed.jpg')
            ->and($item['object']['media']['preview']['src'])->toBe('https://teylabs.com/thumb/storyfeed.jpg')
            ->and($item['object']['body'])->toBe([$body->toPayload()]);
    }

    expect($items[4]['object']['body'][0])->toMatchArray(['src' => 'https://teylabs.com/cards/storyfeed.png', 'alt' => 'Storyfeed', 'width' => 1200, 'height' => 630])
        ->and($items[8]['object']['body'][0]['image'])->toMatchArray(['src' => 'https://teylabs.com/cards/storyfeed.png', 'alt' => 'Storyfeed'])
        ->and($items[5]['object']['body'][0]['image'])->toBe('preview')
        ->and($items[9]['object']['body'][0]['image'])->toBe('image');
});
