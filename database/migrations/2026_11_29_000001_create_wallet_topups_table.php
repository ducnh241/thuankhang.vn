<?php

use App\Models\AuditLog;
use App\Models\Group;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ví tái tiêu dùng dùng được (21/11/2026): mua hàng bằng ví, chuyển ví cho thành viên khác, nạp tiền (chuyển khoản + bill
 * → kế toán duyệt), chuyển từ ví rút tiền sang.
 *   - wallet_topups: lệnh nạp ví (luồng giống đơn hàng: new → awaiting_confirmation → paid | cancelled).
 *   - wallet_transactions: thêm liên kết order_id (mua hàng), topup_id (nạp), counterpart_id (người chuyển / nhận).
 *   - orders.payment_method: transfer (chuyển khoản, như cũ) | wallet (trừ ví tái tiêu dùng, thanh toán ngay).
 *   - Quyền mới wallet_topup + wallet_topup/confirm: tick sẵn cho nhóm đang có order/confirm_payment (Kế toán, Super admin)
 *     vì RoleSeeder không ghi đè nhóm đã có.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_topups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique()->comment('Mã lệnh = nội dung chuyển khoản');
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->bigInteger('amount')->comment('Số tiền thành viên muốn nạp');
            $table->string('status', 30)->default('new')->index();
            $table->timestamp('expires_at')->nullable()->comment('Hạn chuyển khoản');
            $table->timestamp('confirm_requested_at')->nullable()->comment('Lúc thành viên báo đã chuyển khoản');
            $table->string('payment_bill')->nullable()->comment('Ảnh bill (file mã hóa, disk riêng tư)');
            $table->timestamp('paid_at')->nullable();
            $table->bigInteger('paid_amount')->nullable()->comment('Số tiền thực nhận = số cộng vào ví');
            $table->string('payment_reference', 100)->nullable()->unique()->comment('Mã giao dịch ngân hàng');
            $table->string('payment_note', 300)->nullable();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_source', 20)->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->timestamps();

            $table->index(['member_id', 'id']);
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('commission_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('topup_id')->nullable()->after('order_id')->constrained('wallet_topups')->restrictOnDelete();
            $table->foreignId('counterpart_id')->nullable()->after('topup_id')->comment('Người nhận / người chuyển (chuyển ví)')
                ->constrained('members')->restrictOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('payment_method', 20)->default('transfer')->after('total_amount')->comment('transfer | wallet');
        });

        foreach (Group::all() as $group) {
            $keys = $group->permission_data ?? [];
            if (in_array('order/confirm_payment', $keys, true) && ! in_array('wallet_topup/confirm', $keys, true)) {
                $group->forceFill(['permission_data' => array_values(array_unique([...$keys, 'wallet_topup', 'wallet_topup/confirm']))])->saveQuietly();
                AuditLog::record('updated', $group, null, "Tự thêm quyền Nạp ví (xem + duyệt) cho nhóm {$group->name} — nhóm đang có quyền xác nhận thanh toán đơn");
            }
        }
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('payment_method'));
        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('counterpart_id');
            $table->dropConstrainedForeignId('topup_id');
            $table->dropConstrainedForeignId('order_id');
        });
        Schema::dropIfExists('wallet_topups');
    }
};
