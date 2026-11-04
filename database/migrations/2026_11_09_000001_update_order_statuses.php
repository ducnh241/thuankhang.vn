<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Luồng trạng thái đơn mới: new → awaiting_confirmation → paid → processing → completed; cancelled (khách / admin / quá
 * hạn — cancel_source). Bỏ pending_payment (→ new) và expired (→ cancelled, nguồn "expired"). Thêm thông tin xác nhận
 * thanh toán: mã giao dịch ngân hàng (duy nhất — chống xác nhận một khoản tiền cho hai đơn), số tiền thực nhận, người xác nhận.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 30)->default('new')->change();
            $table->timestamp('confirm_requested_at')->nullable()->after('expires_at')->comment('Khách bấm "Tôi đã chuyển khoản"');
            $table->unsignedBigInteger('paid_amount')->nullable()->after('paid_at')->comment('Số tiền thực nhận');
            $table->string('payment_reference', 100)->nullable()->unique()->after('paid_amount')->comment('Mã giao dịch ngân hàng');
            $table->string('payment_note', 300)->nullable()->after('payment_reference');
            $table->foreignId('confirmed_by')->nullable()->after('payment_note')->constrained('users')->nullOnDelete();
            $table->string('cancel_source', 20)->nullable()->after('cancelled_at')->comment('customer | admin | expired');
        });

        DB::table('orders')->where('status', 'pending_payment')->update(['status' => 'new']);
        DB::table('orders')->where('status', 'expired')->update([
            'status' => 'cancelled', 'cancel_source' => 'expired', 'cancel_reason' => 'Quá hạn thanh toán',
            'cancelled_at' => DB::raw('COALESCE(cancelled_at, expires_at)'),
        ]);
        DB::table('orders')->where('status', 'cancelled')->whereNull('cancel_source')->update(['cancel_source' => 'admin']);
    }

    public function down(): void
    {
        DB::table('orders')->where('status', 'awaiting_confirmation')->update(['status' => 'new']);
        DB::table('orders')->where('status', 'new')->update(['status' => 'pending_payment']);
        DB::table('orders')->where('cancel_source', 'expired')->update(['status' => 'expired']);

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('confirmed_by');
            $table->dropUnique(['payment_reference']);
            $table->dropColumn(['confirm_requested_at', 'paid_amount', 'payment_reference', 'payment_note', 'cancel_source']);
            $table->string('status', 20)->default('pending_payment')->change();
        });
    }
};
