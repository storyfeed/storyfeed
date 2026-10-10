<?php

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Exceptions;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedContext;
use Storyfeed\FeedImage;
use Storyfeed\FeedLink;
use Storyfeed\FeedMedia;
use Storyfeed\Models\Activity;
use Storyfeed\Serialization\ActivitySerializer;
use Storyfeed\Support\Avatar;
use Workbench\App\Models\Customer;
use Workbench\App\Models\User;

/*
 * The media slots (todo 627): icon / image / preview on FeedMedia,
 * `entity.media` on the payload, Link objects on the AS2 document. The
 * slots are AS2's property names and the slot is the meaning.
 */

/**
 * A photo-shaped Feedable: the snapshot carries mediaType/width/height and
 * no URL; the resolver mints the full conversion as `image`, links to it as
 * a modal, and the thumb as `preview` — the consumer's exact shape.
 */
function photoModel(): Customer
{
    $model = new class extends Customer
    {
        protected $table = 'customers';

        public static function feedMedia(FeedContext $context): ?FeedMedia
        {
            return FeedMedia::make(
                link: FeedLink::to("/photos/{$context->key()}/full.jpg")->modal(),
                image: FeedImage::make(
                    src: "/photos/{$context->key()}/full.jpg",
                    mediaType: 'image/jpeg',
                    width: 4032,
                    height: 3024,
                    alt: $context->label(),
                ),
                preview: FeedImage::make("/photos/{$context->key()}/thumb.jpg", 'image/jpeg', 400, 300),
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

    expect($media->link?->href)->toBe('/dishes/1')
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

it('takes a FeedLink for a link with a suggestion, and a string as a plain one', function () {
    $media = FeedMedia::make(link: FeedLink::to('/full.jpg')->modal()->attributes(['target' => '_blank']));

    expect($media->href())->toBe('/full.jpg')
        ->and($media->link?->modal)->toBeTrue()
        ->and($media->link?->attributes)->toBe(['target' => '_blank'])
        ->and(FeedMedia::make()->link('/plain')->link?->modal)->toBeFalse()
        ->and(FeedMedia::make()->url('/a')->link(FeedLink::to('/b'))->href())->toBe('/b');
});

it('refuses an entity link with nowhere to go', function (FeedLink $link) {
    expect(fn () => FeedMedia::make()->link($link))->toThrow(InvalidArgumentException::class, 'FeedLink::to($href)');
})->with([
    'the entity itself' => fn () => FeedLink::toEntity('Pad thai'),
    'no href' => fn () => FeedLink::make('Pad thai'),
]);

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
        ->and(FeedMedia::make('/x', 'Label', FeedLink::to('/x')->modal())->media())->toBeNull()
        ->and(FeedMedia::make()->media())->toBeNull();

    $media = FeedMedia::make(preview: '/thumb.jpg')->media();

    expect($media)->toHaveKeys(['icon', 'image', 'preview'])
        ->and($media)->not->toHaveKey('url')
        ->and($media['icon'])->toBeNull()
        ->and($media['preview']['src'])->toBe('/thumb.jpg');
});

it('emits entity.link with its suggestion and the full picture as the image slot', function () {
    $photo = photoModel()::create(['name' => 'Pad thai']);

    Storyfeed::activity('publish', $photo)->publish();

    $object = Storyfeed::feed()->get()->toArray()[0]['object'];

    expect($object['link'])->toBe(['href' => "/photos/{$photo->id}/full.jpg", 'modal' => true, 'attributes' => []])
        ->and($object['media'])->toBe([
            'icon' => null,
            'image' => [
                'src' => "/photos/{$photo->id}/full.jpg",
                'mediaType' => 'image/jpeg',
                'width' => 4032,
                'height' => 3024,
                'alt' => 'Pad thai',
            ],
            'preview' => [
                'src' => "/photos/{$photo->id}/thumb.jpg",
                'mediaType' => 'image/jpeg',
                'width' => 400,
                'height' => 300,
                'alt' => null,
            ],
            // No icon declared, so the avatar is derived (#92).
            'initials' => 'PT',
            'color' => Avatar::color('photo', (string) $photo->id),
            'files' => [],
            'slots' => [],
        ]);
});

it('derives an avatar for an entity whose resolver returns only a link', function () {
    $customer = Customer::create(['name' => 'Acme']);

    Storyfeed::activity('onboard', $customer)->publish();

    $item = Storyfeed::feed()->get()->toArray()[0];

    expect($item['object']['link']['href'])->toBe("/customers/{$customer->id}")
        ->and($item['object']['media'])->toBe(Avatar::fill(null, 'customer', (string) $customer->id, 'Acme'))
        ->and($item['object']['media']['initials'])->toBe('A')
        ->and($item['actor'])->toBeNull();
});

it('draws ? on the neutral colour for an un-snapshotted entity, without calling the resolver', function () {
    photoModel();

    Activity::query()->create([
        'verb' => 'publish',
        'object_type' => 'photo',
        'object_id' => 999,
        'published_at' => now(),
    ]);

    $object = Storyfeed::feed()->get()->toArray()[0]['object'];

    expect($object['link'])->toBeNull()
        ->and($object['media'])->toMatchArray(['icon' => null, 'initials' => '?', 'color' => Avatar::NEUTRAL])
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

    $object = Storyfeed::feed()->get()->toArray()[0]['object'];

    expect($object['label'])->toBe('Burnt')
        ->and($object['link'])->toBeNull()
        ->and($object['media'])->toMatchArray(['icon' => null, 'image' => null, 'preview' => null, 'initials' => 'B']);
    Exceptions::assertReported(RuntimeException::class);
});

it('serializes the link as a bare url, and the image and its derivative as AS2 Links', function () {
    $photo = photoModel()::create(['name' => 'Pad thai']);

    $activity = Storyfeed::activity('publish', $photo)->publish();

    $document = app(ActivitySerializer::class)->activity($activity->fresh(['cachedObject']));

    expect($document['object']['url'])->toBe(url("/photos/{$photo->id}/full.jpg"))
        ->and($document['object']['image'])->toBe([
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
        ])->and($document['object'])->not->toHaveKeys(['icon', 'modal']);
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
            return FeedMedia::make("/users/{$context->key()}")
                ->icon(FeedImage::make("/avatars/{$context->key()}.png", 'image/png', 32, 32));
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
