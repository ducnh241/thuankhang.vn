<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đổi sản phẩm trong đơn (26/11/2026): mỗi lần đổi một dòng (chỉ ghi thêm) — danh sách sản phẩm trước / sau (JSON), tổng tiền
 * (luôn bằng nhau), người đổi, lý do. Quyền order/exchange KHÔNG cấp tự động — quản trị tự tick cho nhóm cần dùng.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_exchanges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name', 150)->nullable();
            $table->string('reason', 300);
            $table->json('before_items');
            $table->json('after_items');
            $table->bigInteger('goods_amount')->comment('Tổng tiền hàng — trước và sau như nhau');
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_exchanges');
    }
};
