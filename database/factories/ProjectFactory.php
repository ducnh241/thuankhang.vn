<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Project> */
class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => fake()->unique()->domainName(),
            'primary_domain' => fake()->unique()->domainName(),
            'status' => 'delivered',
            'price' => 5_000_000,
            'start_date' => now()->subYear(),
            'next_renewal_date' => now()->addMonth(),
        ];
    }
}
