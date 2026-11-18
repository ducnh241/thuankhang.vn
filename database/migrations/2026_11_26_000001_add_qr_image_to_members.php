<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mã QR nhận tiền của thành viên (ảnh QR tài khoản ngân hàng để kế toán quét khi chi hoa hồng): tải lên ở form KYC hoặc ở
 * trang sửa thông tin tài khoản. members.qr_image = đường dẫn file đã mã hóa trên disk riêng tư (cùng cách lưu ảnh CCCD),
 * qr_uploaded_at = lần tải gần nhất. Chỉ thêm cột.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->string('qr_image')->nullable()->after('status')->comment('Ảnh mã QR nhận tiền (file mã hóa, disk riêng tư)');
            $table->timestamp('qr_uploaded_at')->nullable()->after('qr_image');
        });
    }

    public function down(): void
    {
        Schema::table('members', fn (Blueprint $table) => $table->dropColumn(['qr_image', 'qr_uploaded_at']));
    }
};
