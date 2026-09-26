<?php

namespace Storyfeed\Tests\Fixtures\DataCasts;

final readonly class LineItem
{
    public string $sku;

    public int $quantity;

    /** @param  array{sku: string, quantity: int}  $line */
    public function __construct(array $line)
    {
        $this->sku = $line['sku'];
        $this->quantity = $line['quantity'];
    }
}
