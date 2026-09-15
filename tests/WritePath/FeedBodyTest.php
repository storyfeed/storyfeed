<?php

use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedBody;
use Storyfeed\Facades\Storyfeed;
use Storyfeed\FeedThread;
use Workbench\App\Models\Delivery;

final class ShipmentDetail implements FeedBody
{
    use HasPayload;

    public static function name(): string
    {
        return 'acme/shipment';
    }

    public static function version(): int
    {
        return 2;
    }

    public static function upgrade(array $payload, int $from): array
    {
        return $payload;
    }

    public function toPayload(): array
    {
        return [self::KEY => self::name(), self::VERSION => self::version(), 'status' => 'shipped'];
    }
}

it('records a payload-only detail author and preserves renderer metadata beside a thread', function () {
    $body = new ShipmentDetail;
    $thread = FeedThread::make(text: 'Shipped.');
    $activity = Storyfeed::activity('confirm', Delivery::create(['tracking_number' => 'DETAIL-1']))
        ->data(['shipment' => $body->toArray(), '$acme' => ['keep' => true]])
        ->thread($thread)
        ->publish();

    $stored = $activity->fresh()->data;
    expect($stored['shipment'])->toBe($body->toPayload())
        ->and($stored[FeedThread::KEY][FeedThread::VERSION])->toBe(1);

    $node = Storyfeed::feed()->get()->toArray()['items'][0];
    expect($node['data'])->toBe([
        'shipment' => ['$body' => 'acme/shipment', '$v' => 2, 'status' => 'shipped'],
        '$acme' => ['keep' => true],
    ])->and($node['thread'])->toBe($thread->toPayload())
        ->and($node['thread'])->not->toHaveKey('$v');
});

it('allows a storage override without leaking its extras into the authored payload', function () {
    $value = new class
    {
        use HasPayload;

        public function toPayload(): array
        {
            return ['text' => 'Hello'];
        }

        public function toArray(): array
        {
            return [...$this->toPayload(), '$v' => 1];
        }
    };

    expect($value->toArray())->toBe(['text' => 'Hello', '$v' => 1])
        ->and($value->toPayload())->toBe(['text' => 'Hello']);
});
