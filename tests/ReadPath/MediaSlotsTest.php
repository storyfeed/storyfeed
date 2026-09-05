<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Exceptions;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\FeedImage;
use Storyfeed\FeedMedia;
use Storyfeed\Models\Activity;
use Storyfeed\Serialization\ActivitySerializer;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

/*
 * The media slots (todo 627): icon / image / preview / url on FeedMedia,
 * `entity.media` on the payload, Link objects on the AS2 document. The
 * slots are AS2's property names and the slot is the meaning.
 */

/**
 * A photo-shaped Feedable: the snapshot carries mediaType/width/height and
 * no URL; the resolver mints the full conversion as `url` and the thumb as
 * `preview` — the consumer's exact shape.
 */
function photoModel(): Customer
{
    $model = new class extends Customer
    {
        protected $table = 'customers';

        public static function feedMedia(FeedContext $context): ?FeedMedia
        {
            return FeedMedia::make(
                url: FeedImage::make(
                    src: "/photos/{$context->id()}/full.jpg",
                    mediaType: 'image/jpeg',
                    width: 4032,
                    height: 3024,
                    alt: $context->label(),
                ),
                preview: FeedImage::make("/photos/{$context->id()}/thumb.jpg", 'image/jpeg', 400, 300),
            );
        }
    };

    Relation::morphMap(['photo' => $model::class]);

    return $model;
}

it('builds every slot from named arguments', function () {
    $media = FeedMedia::make(
        url: '/dishes/1',
        icon: '/avatars/1.png',
        preview: FeedImage::make('/thumb.jpg', 'image/jpeg', 400, 300, 'Pad thai'),
        image: '/hero.jpg',
    );

    expect($media->url)->toBe('/dishes/1')
        ->and($media->href())->toBe('/dishes/1')
        ->and($media->icon)->toBeInstanceOf(FeedImage::class)
        ->and($media->icon?->src)->toBe('/avatars/1.png')
        ->and($media->preview?->width)->toBe(400)
        ->and($media->preview?->alt)->toBe('Pad thai')
        ->and($media->image?->src)->toBe('/hero.jpg');
});

it('builds the same value fluently, slot by slot', function () {
    $media = FeedMedia::make('/dishes/1')
        ->icon('/avatars/1.png')
        ->preview(FeedImage::make('/thumb.jpg', width: 400, height: 300))
        ->image(null);

    expect($media->icon?->src)->toBe('/avatars/1.png')
        ->and($media->preview?->height)->toBe(300)
        ->and($media->image)->toBeNull()
        ->and($media->url('/elsewhere')->href())->toBe('/elsewhere');
});

it('is immutable from outside despite the fluent setters', function () {
    $media = FeedMedia::make('/x');

    expect(fn () => $media->preview = FeedImage::make('/y'))->toThrow(Error::class);
});

it('reads the href through a url given as an image', function () {
    $media = FeedMedia::make(url: FeedImage::make('/full.jpg', 'image/jpeg', 100, 80));

    expect($media->href())->toBe('/full.jpg')
        ->and($media->url)->toBeInstanceOf(FeedImage::class);
});

it('degrades impossible dimensions to null rather than reserving a zero box', function () {
    $image = FeedImage::make('/x.jpg', width: 0, height: -5);

    expect($image->width)->toBeNull()
        ->and($image->height)->toBeNull()
        ->and($image->toArray())->toBe([
            'src' => '/x.jpg', 'mediaType' => null, 'width' => null, 'height' => null, 'alt' => null,
        ]);
});

it('answers null media when no slot is set, and every slot key when one is', function () {
    expect(FeedMedia::make('/x')->media())->toBeNull()
        ->and(FeedMedia::make('/x', 'Label', ['target' => '_blank'], modal: true)->media())->toBeNull()
        ->and(FeedMedia::make()->media())->toBeNull();

    $media = FeedMedia::make(preview: '/thumb.jpg')->media();

    expect($media)->toHaveKeys(['icon', 'image', 'preview', 'url'])
        ->and($media['icon'])->toBeNull()
        ->and($media['url'])->toBeNull()
        ->and($media['preview']['src'])->toBe('/thumb.jpg');
});

it('emits entity.media on the payload with url as a string and the typed url under media', function () {
    $photo = photoModel()::create(['name' => 'Pad thai']);

    Storyfeed::activity('publish', $photo)->publish();

    $object = Storyfeed::feed()->get()->toArray()['items'][0]['object'];

    expect($object['url'])->toBe("/photos/{$photo->id}/full.jpg")
        ->and($object['media'])->toBe([
            'icon' => null,
            'image' => null,
            'preview' => [
                'src' => "/photos/{$photo->id}/thumb.jpg",
                'mediaType' => 'image/jpeg',
                'width' => 400,
                'height' => 300,
                'alt' => null,
            ],
            'url' => [
                'src' => "/photos/{$photo->id}/full.jpg",
                'mediaType' => 'image/jpeg',
                'width' => 4032,
                'height' => 3024,
                'alt' => 'Pad thai',
            ],
        ]);
});

it('emits media: null for an entity whose resolver returns only a link', function () {
    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];

    expect($item['object']['url'])->toBe("/customers/{$customer->id}")
        ->and($item['object']['media'])->toBeNull()
        ->and($item['actor'])->toBeNull();
});

it('emits media: null for an un-snapshotted entity without calling the resolver', function () {
    photoModel();

    Activity::query()->create([
        'verb' => 'publish',
        'object_type' => 'photo',
        'object_id' => 999,
        'published_at' => now(),
    ]);

    $object = Storyfeed::feed()->get()->toArray()['items'][0]['object'];

    expect($object['url'])->toBeNull()
        ->and($object['media'])->toBeNull()
        ->and($object['label'])->toBeNull();
});

it('degrades a throwing resolver to no url and no media, reported', function () {
    Exceptions::fake();

    $model = new class extends Customer
    {
        protected $table = 'customers';

        public static function feedMedia(FeedContext $context): ?FeedMedia
        {
            throw new RuntimeException('conversion missing');
        }
    };

    Relation::morphMap(['broken' => $model::class]);

    $broken = $model::create(['name' => 'Burnt']);

    Storyfeed::activity('publish', $broken)->publish();

    $object = Storyfeed::feed()->get()->toArray()['items'][0]['object'];

    expect($object['label'])->toBe('Burnt')
        ->and($object['url'])->toBeNull()
        ->and($object['media'])->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});

it('serializes a typed url as an AS2 Link with mediaType and dimensions, and the derivative as preview', function () {
    $photo = photoModel()::create(['name' => 'Pad thai']);

    $activity = Storyfeed::activity('publish', $photo)->publish();

    $document = app(ActivitySerializer::class)->activity($activity->fresh(['cachedObject']));

    expect($document['object']['url'])->toBe([
        'type' => 'Link',
        'href' => url("/photos/{$photo->id}/full.jpg"),
        'mediaType' => 'image/jpeg',
        'name' => 'Pad thai',
        'width' => 4032,
        'height' => 3024,
    ])->and($document['object']['preview'])->toBe([
        'type' => 'Link',
        'href' => url("/photos/{$photo->id}/thumb.jpg"),
        'mediaType' => 'image/jpeg',
        'width' => 400,
        'height' => 300,
    ])->and($document['object'])->not->toHaveKeys(['icon', 'image']);
});

it('keeps a plain href as a bare string url on the AS2 document', function () {
    $customer = Customer::create(['name' => 'Acme']);

    $activity = Storyfeed::activity('onboard', $customer)->publish();

    $document = app(ActivitySerializer::class)->activity($activity->fresh(['cachedObject']));

    expect($document['object']['url'])->toBe(url("/customers/{$customer->id}"))
        ->and($document['object'])->not->toHaveKeys(['icon', 'image', 'preview']);
});

it('serializes an actor icon as a Link and keeps the actor id as its href', function () {
    $model = new class extends User
    {
        protected $table = 'users';

        public static function feedMedia(FeedContext $context): ?FeedMedia
        {
            return FeedMedia::make("/users/{$context->id()}")
                ->icon(FeedImage::make("/avatars/{$context->id()}.png", 'image/png', 32, 32));
        }
    };

    Relation::morphMap(['person' => $model::class]);

    $user = $model::create(['name' => 'Sally', 'email' => 's@example.com']);

    $activity = Storyfeed::activity('onboard', Customer::create(['name' => 'Acme']))->actor($user)->publish();

    $document = app(ActivitySerializer::class)->activity($activity->fresh(['cachedActor', 'cachedObject']));

    expect($document['actor']['id'])->toBe(url("/users/{$user->id}"))
        ->and($document['actor'])->not->toHaveKey('url')
        ->and($document['actor']['icon'])->toBe([
            'type' => 'Link',
            'href' => url("/avatars/{$user->id}.png"),
            'mediaType' => 'image/png',
            'width' => 32,
            'height' => 32,
        ]);
});
