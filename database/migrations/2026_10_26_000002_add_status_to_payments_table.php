<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trạng thái khoản thu: paid = tiền đã nhận (mọi khoản thu cũ), unpaid = khoản phát sinh đã ghi nhưng khách chưa trả —
 * chưa tính doanh thu, cộng vào công nợ; bấm "Đã thu" thì thành paid, paid_at là ngày thu. Chỉ loại "Phát sinh"
 * (type = extra) được để unpaid (Payment::UNPAID_TYPES). Mặc định paid: dữ liệu cũ giữ nguyên, số liệu không đổi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('status', 20)->default('paid')->after('type')->index()->comment('paid = đã thu, unpaid = phát sinh chưa thu');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['status']);
            $table->dropColumn('status');
        });
    }
};
