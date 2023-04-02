<?php

namespace Storyfeed\Data;

use Illuminate\Contracts\Support\Arrayable;
use Spatie\LaravelData\Data;

class FeedObject extends Data
{
    public function __construct(
        public string $type,
        public string $id,
        public string $name,
        public array|Arrayable $data = [],
    ) {
    }
}
