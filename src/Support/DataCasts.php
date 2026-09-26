<?php

namespace Storyfeed\Support;

use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Reads one recorded data value through an Eloquent cast.
 *
 * NOT A REIMPLEMENTATION. Every cast a model's `casts()` accepts works here
 * because Eloquent does the casting: the data keys become the attributes of
 * a model that is never saved, the verb's casts are merged into it with
 * `mergeCasts()`, and the value is read back with `getAttribute()`. A value
 * reaches a cast exactly as a column would give it to one: a scalar as is,
 * an array as its JSON text — so `AsCollection::of()`, `Castable` and a
 * `CastsAttributes` class receive what they receive from a JSON column.
 *
 * DEGRADES, NEVER THROWS. A row recorded before its cast was declared, or
 * one a cast no longer understands, is reported and read as its recorded
 * value: the read path never hides an activity (docs/payload.md).
 *
 * The recorded data is all it has. The stand-in model has no table and no
 * connection, so a cast can never re-read the subject.
 *
 * @internal Used by ActivityContext.
 */
final class DataCasts
{
    /**
     * @param  array<string, mixed>  $data  the activity's recorded data
     * @param  array<string, mixed>  $casts  Eloquent casts, keyed by data key
     */
    public static function get(array $data, array $casts, string $key): mixed
    {
        if (! array_key_exists($key, $casts) || ! array_key_exists($key, $data)) {
            return $data[$key] ?? null;
        }

        try {
            return self::model($data, $casts)->getAttribute($key);
        } catch (Throwable $e) {
            report($e);

            return $data[$key];
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $casts
     */
    private static function model(array $data, array $casts): Model
    {
        $model = new class extends Model
        {
            // A cast never asks the connection for its date format.
            protected $dateFormat = Chronology::FORMAT;
        };

        return $model->mergeCasts($casts)->setRawAttributes(array_map(
            static fn (mixed $value): mixed => is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : $value,
            $data,
        ));
    }
}
