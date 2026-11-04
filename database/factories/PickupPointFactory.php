<?php

namespace Database\Factories;

use App\Models\PickupPoint;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PickupPoint> */
class PickupPointFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'VP '.fake()->unique()->numberBetween(1, 99999),
            'province' => 'ha-noi',
            'address' => fake()->streetAddress(),
            'phone' => '024'.fake()->numerify('#######'),
            'opening_hours' => '8:00 – 17:30, Thứ 2 – Thứ 7',
        ];
    }
}
