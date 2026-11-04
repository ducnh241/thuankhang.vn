<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Thành viên website (đặc tả Step 3, mục 4 — bảng "users" của đặc tả). Tách riêng khỏi bảng users (tài khoản quản trị):
 * đăng nhập bằng guard "member". Người giới thiệu gắn cố định khi đăng ký (chỉ quản trị sửa, có ghi nhật ký).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 15)->unique()->comment('Số di động dạng 0xxxxxxxxx — tên đăng nhập');
            $table->string('email')->nullable()->unique();
            $table->string('full_name', 100);
            $table->string('password');
            $table->string('referral_code', 20)->unique()->comment('Mã giới thiệu của chính thành viên (link ?ref=)');
            $table->foreignId('referrer_id')->nullable()->constrained('members')->restrictOnDelete()->comment('Người giới thiệu (F1 của mình)');
            $table->foreignId('level_id')->constrained('levels')->restrictOnDelete();
            $table->unsignedBigInteger('cumulative_amount')->default(0)->comment('Doanh số cá nhân cộng dồn (đồng) — Step 7');
            $table->timestamp('activated_at')->nullable()->comment('Đơn thanh toán đầu tiên — từ đây mới nhận hoa hồng');
            $table->string('status', 10)->default('active')->comment('active | locked');
            $table->timestamp('last_login_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('members');
    }
};
