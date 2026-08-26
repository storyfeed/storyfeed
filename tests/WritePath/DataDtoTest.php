<?php

use Illuminate\Contracts\Support\Arrayable;
use Storyfeed\Facades\Storyfeed;
use Workbench\App\Models\Delivery;

/*
 * A TYPED DTO AS THE AUTHORING SURFACE for an activity's own payload.
 *
 * The same arrangement FeedEntity has always had for snapshot data, and the
 * same doctrine as verbs: the typed thing is an authoring convenience and
 * STORAGE STAYS A PLAIN ARRAY. That is the property worth testing — not that
 * an Arrayable is accepted, but that accepting one changes nothing downstream.
 */

final class LinkFetch implements Arrayable
{
    public function __construct(
        public readonly string $ip,
        public readonly ?string $geo = null,
        public readonly bool $automated = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['ip' => $this->ip, 'geo' => $this->geo, 'automated' => $this->automated];
    }
}

it('records a DTO as an ordinary array, byte-identical to the array it produces', function () {
    $document = Delivery::create(['tracking_number' => 'DOC-1']);
    $dto = new LinkFetch('99.225.169.111', 'Guelph, Ontario', false);

    $fromDto = Storyfeed::activity()->verb('link.opened', $document)->data($dto)->publish();
    $fromArray = Storyfeed::activity()->verb('link.opened', $document)->data($dto->toArray())->publish();

    expect($fromDto->data)->toBe($dto->toArray())
        // The point of the whole arrangement: a DTO can be introduced or
        // removed later without a migration and without a renderer noticing.
        ->and($fromDto->data)->toBe($fromArray->data);
});

it('carries the DTO payload through to the feed, still uninterpreted', function () {
    $document = Delivery::create(['tracking_number' => 'DOC-2']);

    Storyfeed::activity()->verb('link.opened', $document)->data(new LinkFetch('1.2.3.4'))->publish();

    $item = Storyfeed::feed()->get()->toArray()['items'][0];
    $node = $item['kind'] === 'group' ? $item['children'][0] : $item;

    expect($node['data'])->toBe(['ip' => '1.2.3.4', 'geo' => null, 'automated' => false]);
});
