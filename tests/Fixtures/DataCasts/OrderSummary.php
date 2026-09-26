<?php

namespace Storyfeed\Tests\Fixtures\DataCasts;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Casts\Json;
use Illuminate\Database\Eloquent\Model;

/** @implements Arrayable<string, mixed> */
final readonly class OrderSummary implements Arrayable, Castable
{
    public function __construct(public string $number, public int $total) {}

    public function toArray(): array
    {
        return ['number' => $this->number, 'total' => $this->total];
    }

    /** @param  array<int, mixed>  $arguments */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new class implements CastsAttributes
        {
            public function get(Model $model, string $key, mixed $value, array $attributes): ?OrderSummary
            {
                if ($value === null) {
                    return null;
                }

                $order = Json::decode($value);

                return new OrderSummary($order['number'], $order['total']);
            }

            public function set(Model $model, string $key, mixed $value, array $attributes): mixed
            {
                return [$key => Json::encode($value)];
            }
        };
    }
}
