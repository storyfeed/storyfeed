<?php

namespace Storyfeed\Prototype\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Storyfeed\Prototype\Models\Customer;
use Storyfeed\Prototype\Models\Delivery;

class DeliveryFactory extends Factory
{
    protected $model = Delivery::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'delivery_date' => $this->faker->date(),
            'tracking_number' => $this->faker->isbn13(),
            'status' => $this->faker->randomElement(['draft', 'confirmed', 'shipped', 'delivered']),
        ];
    }
}
