<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hồ sơ KYC của thành viên (Step 9, S10 / A11): mỗi thành viên một hồ sơ; bị từ chối thì gửi lại đè lên hồ sơ cũ.
 * Số CCCD lưu mã hóa (id_number) + bản băm để tra trùng (id_number_hash); ảnh 2 mặt CCCD lưu mã hóa ở disk riêng tư.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_kycs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->unique()->constrained('members')->cascadeOnDelete();
            $table->string('status', 20)->default('pending')->index(); // pending | approved | rejected
            $table->string('full_name', 100); // họ tên theo CCCD
            $table->text('id_number'); // số CCCD, đã mã hóa
            $table->string('id_number_hash', 64)->index();
            $table->date('birth_date');
            $table->string('bank_code', 20);
            $table->string('bank_account_number', 30);
            $table->string('bank_account_name', 100);
            $table->string('front_image');
            $table->string('back_image');
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['bank_code', 'bank_account_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_kycs');
    }
};
