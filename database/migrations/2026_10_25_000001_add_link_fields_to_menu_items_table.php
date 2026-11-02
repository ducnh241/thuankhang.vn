<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mục menu trỏ tới danh mục (category_id) hoặc trang (page_id — chưa có module Trang, chưa có khóa ngoại) thay cho
 * đường dẫn gõ tay; ẩn / hiện từng mục (is_visible). Xóa danh mục: mục menu trỏ tới nó thành mục không có link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->unsignedBigInteger('page_id')->nullable()->after('url')->index();
            $table->foreignId('category_id')->nullable()->after('page_id')->constrained('categories')->nullOnDelete();
            $table->boolean('is_visible')->default(true)->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropIndex(['page_id']);
            $table->dropColumn(['page_id', 'is_visible']);
        });
    }
};
