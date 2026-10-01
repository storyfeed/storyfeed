<?php

use Storyfeed\Body\Image;

it('stores picture facts and a slot without storing a URL', function () {
    expect(Image::make()->caption('USS Butterscotch')->alt('A boat')->width(640)->height(480)->withPreview()->toPayload())
        ->toBe(['$body' => 'Storyfeed/Body/Image', '$v' => 1, 'caption' => 'USS Butterscotch', 'alt' => 'A boat', 'width' => 640, 'height' => 480, 'image' => 'preview'])
        ->and(Image::make('USS Butterscotch', 'A boat', 640, 480)->toPayload())
        ->toBe(Image::make()->caption('USS Butterscotch')->alt('A boat')->width(640)->height(480)->toPayload());
});

it('defaults to preview and can explicitly name each image slot', function () {
    expect(Image::make()->toPayload()['image'])->toBe('preview');
    foreach (['Icon', 'Preview', 'Image'] as $slot) {
        expect(Image::make()->{'with'.$slot}()->toPayload()['image'])->toBe(strtolower($slot));
    }
    expect(fn () => Image::make()->withPreview()->withImage())->toThrow(LogicException::class);
});

it('normalizes malformed and future payloads without accepting a URL slot', function () {
    foreach ([0, 1, 99] as $version) {
        expect(Image::upgrade(['caption' => [], 'alt' => false, 'width' => -1, 'height' => '480', 'image' => 'url', 'src' => '/stale.jpg'], $version))
            ->toBe(['caption' => null, 'alt' => null, 'width' => null, 'height' => null, 'image' => null]);
        expect(Image::upgrade([], $version)['image'])->toBe('preview');
    }
    expect(Image::make(width: 0, height: -1)->toPayload()['width'])->toBeNull();
});
