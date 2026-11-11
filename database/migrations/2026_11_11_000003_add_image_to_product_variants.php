<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ảnh của biến thể: một ảnh trong bộ ảnh sản phẩm (xóa ảnh thì biến thể về ảnh đại diện sản phẩm). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('image_id')->nullable()->after('stock')->constrained('product_images')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_variants', fn (Blueprint $table) => $table->dropConstrainedForeignId('image_id'));
    }
};
