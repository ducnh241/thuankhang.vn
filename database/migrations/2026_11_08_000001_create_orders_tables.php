<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đơn hàng (đặc tả Step 5, mục 4). Tiền số nguyên (đồng). goods_amount = tiền hàng đã gồm VAT; total = goods + ship.
 * Trạng thái: pending_payment → paid → processing → completed; hoặc expired / cancelled. order_items lưu snapshot tên,
 * giá, VAT tại lúc đặt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique()->comment('Tiền tố (tham số order_code_prefix) + 8 ký tự — nội dung chuyển khoản');
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete()->comment('Người mua');
            $table->string('status', 20)->default('pending_payment')->index();
            $table->unsignedBigInteger('goods_amount')->comment('Tiền hàng đã gồm VAT');
            $table->unsignedBigInteger('vat_amount');
            $table->unsignedBigInteger('shipping_fee')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->string('delivery_type', 10)->comment('ship | pickup');
            $table->foreignId('pickup_point_id')->nullable()->constrained('pickup_points')->restrictOnDelete();
            $table->string('recipient_name', 100);
            $table->string('recipient_phone', 15);
            $table->string('province', 30)->nullable()->comment('Giao tận nơi: khóa config/provinces.php');
            $table->string('address', 300)->nullable()->comment('Giao tận nơi: số nhà, đường, phường / xã');
            $table->string('note', 500)->nullable();
            $table->timestamp('expires_at')->nullable()->comment('Hạn thanh toán');
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('processing_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['member_id', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->string('product_name');
            $table->string('sku', 50)->nullable();
            $table->unsignedInteger('qty');
            $table->unsignedBigInteger('unit_price')->comment('Đơn giá đã gồm VAT lúc đặt');
            $table->decimal('vat_rate', 5, 2);
            $table->unsignedBigInteger('line_amount')->comment('unit_price × qty');
            $table->unsignedBigInteger('vat_amount')->comment('Tiền VAT của dòng');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
