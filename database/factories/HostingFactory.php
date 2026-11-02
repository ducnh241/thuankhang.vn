<?php

namespace Database\Factories;

use App\Models\Hosting;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Hosting> */
class HostingFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'VPS-'.fake()->unique()->numberBetween(1, 999), 'provider' => 'Contabo', 'ownership' => 'mine', 'ip' => fake()->ipv4(), 'expire_date' => now()->addMonths(6)];
    }
}
