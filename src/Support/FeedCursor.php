<?php

namespace Storyfeed\Support;

use Illuminate\Pagination\Cursor;

/**
 * A Laravel cursor that transports a feed token without interpreting it.
 *
 * @internal
 */
final class FeedCursor extends Cursor
{
    public function __construct(private readonly string $token)
    {
        parent::__construct([]);
    }

    public function encode(): string
    {
        return $this->token;
    }

    public static function fromEncoded($encodedString): ?static
    {
        return is_string($encodedString) && $encodedString !== ''
            ? new self($encodedString)
            : null;
    }
}
