<?php

use Storyfeed\Body\Image;
use Storyfeed\DeferredMedia;
use Storyfeed\FeedImage;
use Storyfeed\MediaSlot;

it('stores its own picture from a URL or a FeedImage', function () {
    $expected = ['$body' => 'Storyfeed/Body/Image', '$v' => 3, 'src' => 'https://cdn.example.test/day-3.jpg', 'width' => 1200, 'height' => 800, 'alt' => 'Cabinets installed', 'caption' => 'Day 3'];

    expect(Image::make('https://cdn.example.test/day-3.jpg')->alt('Cabinets installed')->width(1200)->height(800)->caption('Day 3')->toPayload())
        ->toBe($expected)
        ->and(Image::make(FeedImage::make('https://cdn.example.test/day-3.jpg', width: 1200, height: 800, alt: 'Cabinets installed'))->caption('Day 3')->toPayload())
        ->toBe($expected)
        ->and(Image::make(FeedImage::make('/a.webp', 'image/webp'))->toPayload())
        ->toBe(['$body' => 'Storyfeed/Body/Image', '$v' => 3, 'src' => '/a.webp', 'mediaType' => 'image/webp']);
});

it('names one of the entity\'s feedMedia() pictures and stores no URL', function () {
    expect(Image::make(DeferredMedia::slot('icon'))->toPayload())
        ->toBe(['$body' => 'Storyfeed/Body/Image', '$v' => 3, 'image' => 'icon'])
        ->and(Image::make(MediaSlot::Image)->toPayload()['image'])->toBe('image')
        ->and(Image::make(DeferredMedia::slot('sparkline'))->toPayload()['image'])->toBe('slots.sparkline')
        ->and(Image::make(MediaSlot::Icon, 'USS Butterscotch', 'A boat', 640, 480)->toPayload())
        ->toBe(['$body' => 'Storyfeed/Body/Image', '$v' => 3, 'width' => 640, 'height' => 480, 'alt' => 'A boat', 'caption' => 'USS Butterscotch', 'image' => 'icon']);
});

it('shows the preview slot when it is given no picture', function () {
    expect(rendered(Image::make())['image'])->toBe('preview')
        ->and(rendered(Image::make())['src'])->toBeNull()
        ->and(Image::make(image: null, caption: 'A boat')->toPayload()['image'])->toBe('preview');
});

it('holds one picture: setting another replaces it', function () {
    $body = Image::make('https://cdn.example.test/a.jpg')->image(MediaSlot::Icon);

    expect($body->toPayload())->not->toHaveKey('src')
        ->and($body->toPayload()['image'])->toBe('icon')
        ->and(Image::make(MediaSlot::Icon)->image('/b.jpg')->toPayload())->toBe(['$body' => 'Storyfeed/Body/Image', '$v' => 3, 'src' => '/b.jpg']);

    foreach (['withIcon', 'withPreview', 'withImage', 'fromFeedMedia'] as $method) {
        expect(method_exists(Image::class, $method))->toBeFalse();
    }
});

it('upgrades v2 rows, where a missing slot was preview and no src was stored', function () {
    foreach ([0, 1, 2] as $version) {
        expect(Image::upgrade(['caption' => [], 'alt' => false, 'width' => -1, 'height' => '480', 'image' => 'url', 'src' => '/stale.jpg'], $version))
            ->toBe(['src' => null, 'mediaType' => null, 'width' => null, 'height' => null, 'alt' => null, 'caption' => null, 'image' => null])
            ->and(Image::upgrade([], $version)['image'])->toBe('preview')
            ->and(Image::upgrade(['image' => 'icon', 'caption' => 'Day 3'], $version))
            ->toBe(['src' => null, 'mediaType' => null, 'width' => null, 'height' => null, 'alt' => null, 'caption' => 'Day 3', 'image' => 'icon']);
    }
});

it('normalizes malformed and future v3 rows, preferring a stored src over a slot', function () {
    foreach ([3, 99] as $version) {
        expect(Image::upgrade(['src' => '/a.jpg', 'image' => 'icon', 'mediaType' => 7], $version))
            ->toMatchArray(['src' => '/a.jpg', 'mediaType' => null, 'image' => null])
            ->and(Image::upgrade(['src' => '', 'image' => 'slots.sparkline'], $version))
            ->toMatchArray(['src' => null, 'image' => 'slots.sparkline'])
            ->and(Image::upgrade([], $version)['image'])->toBeNull();
    }

    expect(rendered(Image::make(width: 0, height: -1))['width'])->toBeNull();
});
