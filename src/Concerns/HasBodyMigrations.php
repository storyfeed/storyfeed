<?php

namespace Storyfeed\Concerns;

use Storyfeed\BodyMigrations\BodyMigrator;

trait HasBodyMigrations
{
    public static function version(): int
    {
        return app(BodyMigrator::class)->version(static::class);
    }

    /** @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function upgrade(array $payload, int $from): array
    {
        return app(BodyMigrator::class)->upgrade(static::class, $payload, $from);
    }
}
