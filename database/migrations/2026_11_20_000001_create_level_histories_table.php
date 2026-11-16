<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Engine lên cấp (Step 7): vết lên cấp của thành viên (level_histories) + mốc "đơn này đã được cộng vào doanh số cộng dồn"
 * (orders.sales_counted_at) để một đơn không bao giờ bị cộng hai lần.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('from_level_id')->nullable()->constrained('levels')->nullOnDelete();
            $table->foreignId('to_level_id')->constrained('levels')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete(); // null = kích hoạt thủ công
            $table->unsignedBigInteger('cumulative_after');
            $table->string('note', 300)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['member_id', 'id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('sales_counted_at')->nullable()->after('paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('sales_counted_at'));
        Schema::dropIfExists('level_histories');
    }
};
