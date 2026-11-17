<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Hoa hồng F1 / F2 và ví (Step 8). Hai bảng chỉ ghi thêm:
 *   commission_ledger   — mỗi đơn đã thanh toán tối đa 1 dòng F1 + 1 dòng F2 (unique order_id + tier); lưu snapshot cấp, tỉ
 *                         lệ, phí doanh nghiệp, cơ sở tính tại lúc thanh toán. status: credited (đã cộng ví) | skipped
 *                         (không được nhận, kèm lý do).
 *   wallet_transactions — bút toán ví của thành viên (amount có dấu); số dư ví = tổng amount.
 * orders.commissioned_at: mốc "đơn này đã tính hoa hồng" để không bao giờ tính hai lần.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('buyer_id')->constrained('members')->restrictOnDelete()->comment('Người mua');
            $table->foreignId('beneficiary_id')->constrained('members')->restrictOnDelete()->comment('Người hưởng');
            $table->string('tier', 2)->comment('F1 = người mua là F1 của người hưởng; F2 = người mua là F2 của người hưởng');
            $table->foreignId('level_id')->nullable()->constrained('levels')->nullOnDelete()->comment('Cấp người hưởng lúc tính');
            $table->string('level_name', 100)->nullable();
            $table->decimal('rate_percent', 5, 2)->default(0);
            $table->unsignedBigInteger('goods_amount')->comment('Tiền hàng của đơn (giá bán đã gồm thuế), không gồm phí giao hàng');
            $table->decimal('fee_percent', 5, 2)->comment('Phí doanh nghiệp lúc tính');
            $table->unsignedBigInteger('base_amount')->comment('Cơ sở hoa hồng = tiền hàng × (1 − phí)');
            $table->unsignedBigInteger('amount')->default(0)->comment('Hoa hồng = floor(cơ sở × tỉ lệ)');
            $table->string('status', 10)->comment('credited | skipped');
            $table->string('skip_reason', 200)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['order_id', 'tier']);
            $table->index(['beneficiary_id', 'id']);
        });

        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->string('type', 20)->comment('commission (các loại rút tiền / điều chỉnh thêm ở Step 9)');
            $table->bigInteger('amount')->comment('Có dấu: + cộng ví, − trừ ví');
            $table->bigInteger('balance_after');
            $table->foreignId('commission_id')->nullable()->constrained('commission_ledger')->restrictOnDelete();
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['member_id', 'id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('commissioned_at')->nullable()->after('sales_counted_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('commissioned_at'));
        Schema::dropIfExists('wallet_transactions');
        Schema::dropIfExists('commission_ledger');
    }
};
