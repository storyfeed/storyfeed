<?php

use Carbon\CarbonImmutable;
use Storyfeed\Support\Entity;

/**
 * Storyfeed\Support\Entity reads one entity object: the three states the
 * payload keeps apart (a live entity, a degraded one, a tombstone), media,
 * bodies and the utterance, and its link.
 */
function entityPayload(array $overrides = []): array
{
    return [
        'type' => 'photo', 'id' => '88', 'label' => 'Pad thai', 'url' => 'https://example.test/photos/88',
        'attributes' => ['target' => '_blank', 'data-turbo' => false, 'download' => true],
        'modal' => true,
        'data' => ['table' => 4],
        'media' => [
            'icon' => null, 'image' => null,
            'preview' => ['src' => '/thumb.jpg', 'mediaType' => 'image/jpeg', 'width' => 400, 'height' => 300, 'alt' => null],
            'url' => null,
            'files' => [['type' => 'Document', 'href' => '/menu.pdf', 'mediaType' => 'application/pdf', 'name' => 'Menu']],
        ],
        'body' => [['$body' => 'Storyfeed/Body/Prose', '$v' => 1, 'text' => 'Spicy']],
        'tombstone' => null,
        'content' => 'Extra lime',
        'mediaType' => 'text/markdown',
        'attributedTo' => 'https://example.test/authors/42',
        ...$overrides,
    ];
}

it('reads a live entity by named accessors', function () {
    $entity = Entity::of(entityPayload(), 'object');

    expect($entity->type())->toBe('photo')
        ->and($entity->id())->toBe('88')
        ->and($entity->label())->toBe('Pad thai')
        ->and($entity->url())->toBe('https://example.test/photos/88')
        ->and($entity->attributes())->toBe(['target' => '_blank', 'data-turbo' => false, 'download' => true])
        ->and($entity->isModal())->toBeTrue()
        ->and($entity->data()->integer('table'))->toBe(4)
        ->and($entity->media()->get('preview.src'))->toBe('/thumb.jpg')
        ->and($entity->files()->pluck('name')->all())->toBe(['Menu'])
        ->and($entity->bodies()->pluck('$body')->all())->toBe(['Storyfeed/Body/Prose'])
        ->and($entity->content())->toBe('Extra lime')
        ->and($entity->mediaType())->toBe('text/markdown')
        ->and($entity->attributedTo())->toBe('https://example.test/authors/42')
        ->and($entity->role())->toBe('object')
        ->and($entity->isDegraded())->toBeFalse()
        ->and($entity->isTombstone())->toBeFalse()
        ->and($entity->formerType())->toBeNull()
        ->and($entity->deletedAt())->toBeNull()
        ->and((string) $entity)->toBe('Pad thai');
});

it('draws a link with its attributes, and plain text without a url', function () {
    expect(Entity::of(entityPayload())->toHtml())
        ->toBe('<a href="https://example.test/photos/88" target="_blank" download>Pad thai</a>')
        ->and(Entity::of(entityPayload(['url' => null, 'label' => 'A & B']))->toHtml())->toBe('A &amp; B');
});

it('reads a degraded entity with its role placeholder', function () {
    $degraded = entityPayload(['label' => null, 'url' => null, 'media' => null, 'body' => null]);

    expect(Entity::of($degraded, 'actor')->isDegraded())->toBeTrue()
        ->and(Entity::of($degraded, 'actor')->toString())->toBe('Someone')
        ->and(Entity::of($degraded, 'target')->toString())->toBe('Something')
        ->and(Entity::of($degraded)->toString())->toBe('Something')
        ->and(Entity::of($degraded)->media())->toBeNull()
        ->and(Entity::of($degraded)->files())->toBeEmpty()
        ->and(Entity::of($degraded)->bodies())->toBeEmpty();
});

it('reads a tombstone', function () {
    $tombstone = entityPayload([
        'type' => 'storyfeed.tombstone', 'id' => '17', 'label' => null, 'url' => null,
        'tombstone' => ['formerType' => 'order', 'deleted' => '2026-09-23T12:00:00.000000Z', 'approximate' => false, 'removedBy' => null],
    ]);

    $entity = Entity::of($tombstone, 'object');

    expect($entity->isTombstone())->toBeTrue()
        ->and($entity->isDegraded())->toBeFalse()
        ->and($entity->formerType())->toBe('order')
        ->and($entity->deletedAt())->toBeInstanceOf(CarbonImmutable::class)
        ->and($entity->deletedAt()->toDateString())->toBe('2026-09-23')
        ->and($entity->toString())->toBe('a removed order')
        ->and(Entity::of($tombstone, 'actor')->toString())->toBe('a former order')
        ->and(Entity::of([...$tombstone, 'tombstone' => [...$tombstone['tombstone'], 'formerType' => null, 'deleted' => null]])->toString())->toBe('a removed item')
        ->and(Entity::of([...$tombstone, 'label' => 'Order #7'])->toString())->toBe('Order #7');
});

it('hands the payload back unchanged', function () {
    $entity = Entity::of(entityPayload(), 'object');

    expect($entity->toArray())->toBe(entityPayload())
        ->and($entity['label'])->toBe('Pad thai')
        ->and($entity->get('media.preview.width'))->toBe(400)
        ->and(Entity::of($entity)->role())->toBe('object');

    expect(fn () => $entity['label'] = 'x')->toThrow(LogicException::class, 'Entity is read-only.');
});
