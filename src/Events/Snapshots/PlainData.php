<?php

namespace Storyfeed\Events\Snapshots;

use InvalidArgumentException;

/** @internal Detach references and reject mutable values in consumer data. */
final class PlainData
{
    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public static function freeze(array $data): array
    {
        $copy = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = self::freeze($value);
            } elseif ($value !== null && ! is_scalar($value)) {
                throw new InvalidArgumentException('Event snapshots accept only arrays, scalars and null.');
            }
            $copy[$key] = $value;
        }

        return $copy;
    }
}
