<?php

use Illuminate\Support\Collection;
use Storyfeed\Body\Component;
use Storyfeed\Body\Excerpt;
use Storyfeed\Body\KeyValue;
use Storyfeed\Exceptions\IncompleteFeedValue;
use Storyfeed\FeedEntity;
use Storyfeed\FeedImage;
use Storyfeed\FeedLink;
use Storyfeed\FeedMedia;
use Storyfeed\FeedResource;
use Storyfeed\Support\ShapeSignature;
use Workbench\App\Models\Delivery;

/** Everything a snapshot stores from an entity. */
function entityShape(FeedEntity $entity): array
{
    return [
        'label' => $entity->label,
        'data' => $entity->data,
        'body' => $entity->body,
        'content' => $entity->content,
        'mediaType' => $entity->mediaType,
        'attributedTo' => $entity->attributedTo,
        'shape' => ShapeSignature::for($entity, Delivery::class),
    ];
}

/** Everything the payload and the AS2 document read from media. */
function mediaShape(FeedMedia $media): array
{
    return [
        'href' => $media->href(),
        'label' => $media->label,
        'attributes' => $media->attributes,
        'modal' => $media->modal,
        'media' => $media->media(),
        'body' => $media->body,
    ];
}

it('builds the same entity chained or named', function () {
    $fluent = FeedEntity::make()
        ->label('Order #1042')
        ->data(['total' => 12, 'currency' => 'GBP'])
        ->data('lines', new Collection([1, 2]))
        ->body(Excerpt::make()->text('Leave at the door.'))
        ->body(KeyValue::make(['Seat' => 4]), 'A line of text')
        ->content('Deliver by noon')
        ->mediaType('text/plain')
        ->attributedTo('urn:author:1');

    $named = FeedEntity::make(
        label: 'Order #1042',
        data: ['total' => 12, 'currency' => 'GBP', 'lines' => new Collection([1, 2])],
        content: 'Deliver by noon',
        mediaType: 'text/plain',
        attributedTo: 'urn:author:1',
        body: [Excerpt::make('Leave at the door.'), KeyValue::make(['Seat' => 4]), 'A line of text'],
    );

    expect(entityShape($fluent))->toBe(entityShape($named))
        ->and($fluent->data['lines'])->toBe([1, 2])
        ->and($fluent->body)->toHaveCount(3);
});

it('changes the entity it is called on, and returns it', function () {
    $entity = FeedEntity::make();

    expect($entity->label('A'))->toBe($entity)
        ->and($entity->label)->toBe('A')
        ->and((new ReflectionClass(FeedEntity::class))->isReadOnly())->toBeFalse();
});

it('merges data as View::with() does: arrays merge, a key sets one', function () {
    $entity = FeedEntity::make(data: ['a' => 1, 'b' => 2])
        ->data(['b' => 3, 'c' => 4])
        ->data('d', 5)
        ->data(new Collection(['e' => 6]));

    expect($entity->data)->toBe(['a' => 1, 'b' => 3, 'c' => 4, 'd' => 5, 'e' => 6]);
});

it('appends bodies in the order written, and reads them when used', function () {
    $component = Component::make();
    $entity = FeedEntity::make(body: 'first')->body($component, null, '')->body((fn () => yield Excerpt::make('third'))());
    $component->name('Card');

    expect(array_column($entity->body, '$body'))->toBe(['Storyfeed/Body/Prose', 'Storyfeed/Body/Component', 'Storyfeed/Body/Excerpt'])
        // A generator is kept, so a second read is not an empty one.
        ->and($entity->body)->toHaveCount(3);
});

it('supports when() and unless()', function () {
    $entity = FeedEntity::make()
        ->label('Order')
        ->when(true, fn (FeedEntity $e) => $e->data('rush', true))
        ->unless(true, fn (FeedEntity $e) => $e->data('slow', true));

    expect($entity->data)->toBe(['rush' => true]);
});

it('stores a tombstone closure for later, and reads nothing from it yet', function () {
    $configure = fn ($tombstone) => $tombstone;
    $entity = FeedEntity::make('Order')->tombstone($configure);

    expect($entity->tombstone)->toBe($configure)
        ->and(FeedEntity::make()->tombstone)->toBeNull();
});

it('has no component any more', function () {
    expect(property_exists(FeedEntity::class, 'component'))->toBeFalse()
        ->and(method_exists(FeedEntity::class, 'component'))->toBeFalse();
});

it('builds the same media chained or named', function () {
    $fluent = FeedMedia::make()
        ->url(FeedImage::make()->src('/full.jpg')->width(800)->height(600))
        ->label('Fresh')
        ->attributes(['data-a' => 1])
        ->attributes('data-b', 2)
        ->modal()
        ->icon('/icon.png')
        ->preview(FeedImage::make('/thumb.jpg')->alt('Thumb'))
        ->image('/hero.jpg')
        ->files(FeedResource::make()->href('/a.pdf')->mediaType('application/pdf'))
        ->files([FeedResource::make('/b.zip')])
        ->body(fn () => Excerpt::make('Resolved late'));

    $named = FeedMedia::make(
        url: FeedImage::make(src: '/full.jpg', width: 800, height: 600),
        label: 'Fresh',
        attributes: ['data-a' => 1, 'data-b' => 2],
        modal: true,
        icon: '/icon.png',
        preview: FeedImage::make(src: '/thumb.jpg', alt: 'Thumb'),
        image: '/hero.jpg',
        files: [FeedResource::make(href: '/a.pdf', mediaType: 'application/pdf'), FeedResource::make('/b.zip')],
        body: fn () => Excerpt::make('Resolved late'),
    );

    expect(mediaShape($fluent))->toBe(mediaShape($named))
        ->and($fluent->files)->toHaveCount(2);
});

it('builds the same image, link and resource chained or named', function () {
    expect(FeedImage::make()->src('/a.jpg')->mediaType('image/jpeg')->width(10)->height(0)->alt('A')->toArray())
        ->toBe(FeedImage::make(src: '/a.jpg', mediaType: 'image/jpeg', width: 10, height: 0, alt: 'A')->toArray())
        ->and(FeedLink::make()->label('Recall')->href('https://example.test')->toPayload())
        ->toBe(FeedLink::make(label: 'Recall', href: 'https://example.test')->toPayload())
        ->and(FeedResource::make()->href('/a.mp4')->mediaType('video/mp4')->name('Clip')->type('Video')->toPayload())
        ->toBe(FeedResource::make(href: '/a.mp4', mediaType: 'video/mp4', name: 'Clip', type: 'Video')->toPayload());
});

it('supports when() on media, images, links and resources', function () {
    expect(FeedMedia::make()->when(true, fn (FeedMedia $m) => $m->modal())->modal)->toBeTrue()
        ->and(FeedImage::make('/a')->when(true, fn (FeedImage $i) => $i->alt('A'))->alt)->toBe('A')
        ->and(FeedLink::make('A')->when(false, fn (FeedLink $l) => $l->href('/x'))->href)->toBeNull()
        ->and(FeedResource::make('/a')->unless(false, fn (FeedResource $r) => $r->name('A'))->name)->toBe('A');
});

it('names the method to call when a required value was never set', function (Closure $use, string $message) {
    expect($use)->toThrow(IncompleteFeedValue::class, $message);
})->with([
    'FeedLink' => [fn () => FeedLink::make()->href('/x')->toPayload(), 'FeedLink has no label. Call ->label(…) on it, or pass label: to FeedLink::make().'],
    'FeedImage' => [fn () => FeedImage::make()->alt('A')->toArray(), 'FeedImage has no src. Call ->src(…) on it, or pass src: to FeedImage::make().'],
    'FeedResource' => [fn () => FeedResource::make()->name('A')->toPayload(), 'FeedResource has no href. Call ->href(…) on it, or pass href: to FeedResource::make().'],
    'FeedMedia url' => [fn () => FeedMedia::make()->url(FeedImage::make())->href(), 'FeedImage has no src.'],
]);

it('starts an entity and media empty, with nothing required', function () {
    expect(entityShape(FeedEntity::make()))->toMatchArray(['label' => null, 'data' => [], 'body' => []])
        ->and(FeedMedia::make()->media())->toBeNull();
});
