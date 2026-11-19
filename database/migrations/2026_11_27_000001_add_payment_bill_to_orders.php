<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ảnh bill chuyển khoản của đơn hàng: thành viên BẮT BUỘC tải lên trước khi bấm "Tôi đã chuyển khoản" để kế toán đối chiếu.
 * orders.payment_bill = đường dẫn file đã mã hóa trên disk riêng tư (cùng cách lưu ảnh CCCD). Chỉ thêm cột; đơn cũ để NULL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_bill')->nullable()->after('confirm_requested_at')->comment('Ảnh bill chuyển khoản khách tải lên (file mã hóa, disk riêng tư)');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('payment_bill'));
    }
};
