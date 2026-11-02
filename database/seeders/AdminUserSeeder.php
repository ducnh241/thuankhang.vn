<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminUserSeeder extends Seeder
{
    /**
     * Tạo (hoặc cập nhật) tài khoản admin từ ADMIN_EMAIL / ADMIN_PASSWORD trong .env.
     */
    public function run(): void
    {
        $email = env('ADMIN_EMAIL');
        $password = env('ADMIN_PASSWORD');

        if (! $email || ! $password) {
            throw new RuntimeException('Cần đặt ADMIN_EMAIL và ADMIN_PASSWORD trong .env trước khi seed.');
        }

        if (strlen($password) < 12) {
            throw new RuntimeException('ADMIN_PASSWORD phải có ít nhất 12 ký tự.');
        }

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => env('ADMIN_NAME', 'Admin'),
                'password' => $password, // cast 'hashed' trên model sẽ tự băm
                'email_verified_at' => now(),
                'is_super_admin' => true,
                'status' => true,
            ]
        );
    }
}
