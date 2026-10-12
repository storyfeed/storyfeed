<?php

namespace Storyfeed\Support;

use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Shortens a string to its column's length on write, ending it with "…".
 *
 * A label is whatever the app makes it, and a varchar(255) column refuses a
 * longer one on PostgreSQL and MySQL, failing the whole snapshot write (a
 * 500-character poll option did). A feed shows a label, it doesn't store a
 * document in it, so a shortened label is a better outcome than a failed
 * write. SQLite stores the full value, which is why this is a cast rather than
 * something a test would have caught.
 *
 * Counts characters, as varchar does on every supported engine.
 *
 * @internal
 */
class FitsColumn implements CastsInboundAttributes
{
    public function __construct(
        protected int $length = 255,
    ) {}

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = (string) $value;

        return mb_strlen($value) <= $this->length
            ? $value
            : mb_substr($value, 0, $this->length - 1).'…';
    }
}
