<?php

namespace Database\Factories;

use App\Models\Level;
use App\Models\Member;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<Member> */
class MemberFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'phone' => '09'.fake()->unique()->numerify('########'),
            'full_name' => fake()->name(),
            'password' => static::$password ??= Hash::make('password'),
            'referral_code' => Member::generateReferralCode(),
            'level_id' => fn () => Level::where('threshold_amount', 0)->value('id') ?? Level::create(['name' => 'Chưa kích hoạt', 'sort_order' => 0, 'threshold_amount' => 0])->id,
        ];
    }

    public function locked(): static
    {
        return $this->state(['status' => Member::STATUS_LOCKED]);
    }
}
