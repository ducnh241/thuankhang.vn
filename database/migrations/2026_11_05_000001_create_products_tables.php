<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sản phẩm (đặc tả Step 2 / A6). Giá bán lưu số nguyên (đồng) và ĐÃ GỒM VAT; VAT % theo từng sản phẩm. Xóa mềm: đơn
 * hàng (Step 5) tham chiếu sản phẩm, giá lưu snapshot ở order_items.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->string('name', 255);
            $table->string('slug', 255)->unique();
            $table->string('sku', 50)->nullable()->unique();
            $table->unsignedBigInteger('price')->comment('Giá bán đã gồm VAT (đồng)');
            $table->decimal('vat_rate', 5, 2)->comment('VAT % của sản phẩm');
            $table->string('short_description', 500)->nullable();
            $table->longText('description')->nullable();
            $table->boolean('status')->default(true)->comment('Đang bán (hiện trên cửa hàng)');
            $table->boolean('is_featured')->default(false)->comment('Nổi bật ở trang chủ');
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'sort_order']);
        });

        Schema::create('product_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('0 = ảnh đại diện');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_images');
        Schema::dropIfExists('products');
    }
};
