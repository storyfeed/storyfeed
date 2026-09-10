<?php

use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\FeedImage;
use Storyfeed\FeedMedia;
use Storyfeed\MediaSlot;
use Workbench\App\Models\Customer;

/*
 * MediaSlot: a stored reference to one of FeedMedia's image slots, so a
 * detail can say "my picture is my icon" without holding the image and the
 * URL that ages with it. The enum is core's because the slots are; the
 * helpers on InteractsWithFeed exist for the call site's readability.
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
    expect(MediaSlot::tryFrom('url'))->toBeNull();
});

it('forwards from the trait so a toFeed() reads as a sentence', function () {
    $model = new class extends Customer
    {
        use InteractsWithFeed;
    };

    expect($model->feedMediaIcon())->toBe(MediaSlot::Icon)
        ->and($model->feedMediaPreview())->toBe(MediaSlot::Preview)
        ->and($model->feedMediaImage())->toBe(MediaSlot::Image);
});

it('is a reference and never a resolution: naming a slot does not touch the resolver', function () {
    Customer::$lastContext = null;

    $model = new class extends Customer
    {
        use InteractsWithFeed;
    };

    $model->feedMediaIcon();

    // The helper returns a name. Nothing was minted, nothing was called.
    expect(Customer::$lastContext)->toBeNull();
});
