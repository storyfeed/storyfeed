<?php

namespace Storyfeed\Prototype\Models;

use Storyfeed\Concerns\InteractsWithFeed;
use Storyfeed\Prototype\Factories\CustomerFactory;

class Customer extends BaseModel
{
    use InteractsWithFeed;

    protected $casts = [
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function newFactory()
    {
        return CustomerFactory::new();
    }
}
