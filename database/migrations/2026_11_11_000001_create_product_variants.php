<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biến thể sản phẩm (một nhóm phân loại / sản phẩm, VD "Màu sắc": 401 Cam san hô, 402 Hồng đào…). Giá NULL = giá sản phẩm;
 * tồn NULL = không theo dõi. Sản phẩm có biến thể đang bật thì khách bắt buộc chọn; tồn kho tính theo biến thể.
 * order_items lưu snapshot tên biến thể (biến thể bị xóa sau này vẫn còn tên trong đơn).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('variant_label', 50)->nullable()->after('stock')->comment('Tên nhóm phân loại, VD Màu sắc');
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('sku', 50)->nullable()->unique();
            $table->unsignedBigInteger('price')->nullable()->comment('NULL = giá sản phẩm');
            $table->integer('stock')->nullable()->comment('NULL = không theo dõi tồn kho');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->index(['product_id', 'sort_order']);
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('variant_id')->nullable()->after('product_id')->constrained('product_variants')->nullOnDelete();
            $table->string('variant_name', 100)->nullable()->after('product_name');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('variant_id');
            $table->dropColumn('variant_name');
        });
        Schema::dropIfExists('product_variants');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn('variant_label'));
    }
};
