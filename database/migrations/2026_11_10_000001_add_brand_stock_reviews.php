<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sản phẩm: thương hiệu, xuất xứ, tồn kho (NULL = không theo dõi tồn). Đơn: stock_reserved = đơn đang giữ hàng (trừ tồn lúc
 * đặt, trả lại khi hủy — chống trả hai lần). Đánh giá sản phẩm: 1–5 sao, mỗi thành viên một đánh giá / sản phẩm, chỉ người
 * đã mua (đơn đã thanh toán); quản trị ẩn / hiện.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('brand', 100)->nullable()->after('sku');
            $table->string('origin', 100)->nullable()->after('brand')->comment('Xuất xứ');
            $table->integer('stock')->nullable()->after('vat_rate')->comment('NULL = không theo dõi tồn kho');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('stock_reserved')->default(false)->after('total_amount');
        });

        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('is_visible')->default(true)->index();
            $table->timestamps();
            $table->unique(['product_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('stock_reserved'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['brand', 'origin', 'stock']));
    }
};
