<?php

namespace Storyfeed\Tests\BodyMigrations\Fixtures;

use Storyfeed\Concerns\HasBodyMigrations;
use Storyfeed\Concerns\HasPayload;
use Storyfeed\Contracts\FeedBody;

final class Attachment implements FeedBody
{
    use HasBodyMigrations;
    use HasPayload;

    public function __construct(public int $shape = 1) {}

    public static function bodyType(): string
    {
        return 'Acme/Attachment';
    }

    public function toPayload(): array
    {
        $payload = ['size' => 1024, 'legacy' => true, 'mediaType' => 'IMAGE/PNG'];
        if ($this->shape >= 2) {
            $payload['bytes'] = $payload['size'];
            unset($payload['size']);
        }
        if ($this->shape >= 3) {
            unset($payload['legacy']);
        }
        if ($this->shape >= 4) {
            $payload['caption'] = null;
        }
        if ($this->shape >= 5) {
            $payload['mediaType'] = strtolower($payload['mediaType']);
        }
        if ($this->shape >= 6) {
            $payload['label'] = 'Attachment';
        }

        return [self::KEY => self::bodyType(), self::VERSION => self::version(), ...$payload];
    }
}
