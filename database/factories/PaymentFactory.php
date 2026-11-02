<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return ['project_id' => Project::factory(), 'amount' => 1_000_000, 'type' => 'new', 'paid_at' => now(), 'method' => 'bank_transfer'];
    }
}
