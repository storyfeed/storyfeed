<?php

namespace Storyfeed\Prototype\Models;

use Storyfeed\Prototype\Factories\DeliveryFactory;

class Delivery extends BaseModel
{
    protected $casts = [
        'delivery_date' => 'date:Y-m-d',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected static function newFactory()
    {
        return DeliveryFactory::new();
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }
}
