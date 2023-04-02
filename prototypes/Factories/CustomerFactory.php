<?php

namespace Storyfeed\Prototype\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Storyfeed\Prototype\Models\Customer;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->company(),
        ];
    }
}
