<?php

namespace Database\Factories;

use App\Models\Member;
use App\Models\MemberKyc;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MemberKyc> */
class MemberKycFactory extends Factory
{
    public function definition(): array
    {
        $idNumber = fake()->unique()->numerify('0010########');

        return [
            'member_id' => Member::factory(),
            'status' => MemberKyc::STATUS_PENDING,
            'full_name' => 'Nguyễn Văn An',
            'id_number' => $idNumber,
            'id_number_hash' => MemberKyc::hashIdNumber($idNumber),
            'birth_date' => '1990-05-20',
            'bank_code' => 'VCB',
            'bank_account_number' => fake()->unique()->numerify('00110########'),
            'bank_account_name' => 'NGUYEN VAN AN',
            'front_image' => 'kyc/0/front.enc',
            'back_image' => 'kyc/0/back.enc',
            'submitted_at' => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(['status' => MemberKyc::STATUS_APPROVED, 'reviewed_at' => now()]);
    }

    public function rejected(string $reason = 'Ảnh CCCD bị mờ'): static
    {
        return $this->state(['status' => MemberKyc::STATUS_REJECTED, 'reject_reason' => $reason, 'reviewed_at' => now()]);
    }
}
