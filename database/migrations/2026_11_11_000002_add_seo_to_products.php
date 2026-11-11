<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tiêu đề / mô tả SEO của sản phẩm (để trống: dùng tên / mô tả ngắn). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('meta_title', 70)->nullable()->after('description');
            $table->string('meta_description', 160)->nullable()->after('meta_title');
        });
    }

    public function down(): void
    {
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['meta_title', 'meta_description']));
    }
};
