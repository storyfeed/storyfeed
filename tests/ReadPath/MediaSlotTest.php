<?php

use Storyfeed\Body\Image;
use Storyfeed\Body\MediaObject;
use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\DeferredMedia;
use Storyfeed\FeedImage;
use Storyfeed\FeedMedia;
use Storyfeed\FeedResource;
use Storyfeed\MediaSlot;
use Workbench\App\Models\Customer;

/*
 * MediaSlot and getFeedMedia() (#88): a body shows one of the model's
 * feedMedia() pictures by naming its slot, without holding the image and
 * the URL that ages with it. The value is delivered when the feed is read.
 */

it('names exactly the image slots the payload carries, spelled as the payload spells them', function () {
    $media = FeedMedia::make(icon: '/i.png', preview: '/p.png', image: '/h.png')->media();

    $slots = array_map(fn (MediaSlot $slot) => $slot->value, MediaSlot::cases());

    expect($slots)->toBe(['icon', 'preview', 'image']);

    foreach (MediaSlot::cases() as $slot) {
        // A renderer reads `entity.media[$slot->value]` and finds the minted
        // FeedImage there: the reference and the payload key are one word.
        expect($media[$slot->value])->toBe(FeedImage::from(match ($slot) {
            MediaSlot::Icon => '/i.png',
            MediaSlot::Preview => '/p.png',
            MediaSlot::Image => '/h.png',
        })->toArray());
    }

    // `url` is where the resource lives and where a tap goes, not a picture
    // of it, so it is not a slot a stored block may name.
    expect(MediaSlot::tryFrom('url'))->toBeNull()
        ->and(DeferredMedia::tryFromPayload('url'))->toBeNull();
});

it('gives a Feedable getFeedMedia() and its three shorthands, as typed values', function () {
    $model = new class extends Customer
    {
        use InteractsWithFeed;
    };

    expect($model->feedMediaIcon())->toEqual($model->getFeedMedia('icon'))
        ->and($model->feedMediaIcon()->value)->toBe('icon')
        ->and($model->feedMediaPreview())->toEqual($model->getFeedMedia(MediaSlot::Preview))
        ->and($model->feedMediaImage()->value)->toBe('image')
        ->and($model->getFeedMedia('sparkline')->value)->toBe('slots.sparkline')
        ->and(Image::make($model->feedMediaIcon())->toPayload()['image'])->toBe('icon')
        ->and(MediaObject::make()->image($model->getFeedMedia('sparkline'))->toPayload()['image'])->toBe('slots.sparkline');

    // Never a string, so it cannot be stored as a URL by mistake.
    expect(fn () => FeedImage::make()->src($model->feedMediaIcon()))->toThrow(TypeError::class);
});

it('is delivered later: getFeedMedia() does not touch the resolver', function () {
    Customer::$lastContext = null;

    $model = new class extends Customer
    {
        use InteractsWithFeed;
    };

    $model->getFeedMedia('icon');
    $model->feedMediaPreview();

    expect(Customer::$lastContext)->toBeNull();
});

it('refuses a custom slot name that is not a plain word', function (string $name) {
    expect(fn () => DeferredMedia::slot($name))->toThrow(InvalidArgumentException::class);
})->with(['', 'slots.sparkline', 'two words', '1st', 'a/b']);

it('writes custom slots under media.slots and refuses the built-in names', function () {
    $sparkline = FeedImage::make('data:image/svg+xml;base64,PHN2Zy8+', 'image/svg+xml', 120, 24, 'Orders this week');
    $media = FeedMedia::make()
        ->slot('sparkline', $sparkline)
        ->slot('report', FeedResource::make('/report.pdf', 'application/pdf', 'report.pdf'));

    expect($media->media())->toBe([
        'icon' => null,
        'image' => null,
        'preview' => null,
        'initials' => null,
        'color' => null,
        'files' => [],
        'slots' => [
            'sparkline' => $sparkline->toArray(),
            'report' => FeedResource::make('/report.pdf', 'application/pdf', 'report.pdf')->toArray(),
        ],
    ])
        ->and(FeedMedia::make(icon: '/i.png')->media()['slots'])->toBe([])
        ->and(fn () => FeedMedia::make()->slot('icon', $sparkline))->toThrow(InvalidArgumentException::class, 'icon()')
        ->and(fn () => FeedMedia::make()->slot('spark.line', $sparkline))->toThrow(InvalidArgumentException::class);
});
