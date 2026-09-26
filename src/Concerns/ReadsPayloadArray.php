<?php

namespace Storyfeed\Concerns;

use LogicException;

/**
 * Read-only array access over a slice of the payload, so a reader object
 * answers `$item['verb']` exactly as the array it wraps did. Writes throw,
 * as they do on FeedPage: a reader never changes the JSON.
 *
 * @internal
 */
trait ReadsPayloadArray
{
    /**
     * A value by key, with dot notation for nested keys: `get('object.label')`.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->payload, $key, $default);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->payload;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->payload;
    }

    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->payload);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->payload[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException(class_basename(static::class).' is read-only.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException(class_basename(static::class).' is read-only.');
    }
}
