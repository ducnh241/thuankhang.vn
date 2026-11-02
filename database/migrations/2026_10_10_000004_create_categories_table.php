<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Danh mục dùng chung, phân loại bằng `type` như nhánh main (1 = dự án).
 * projects.cat_id đã có sẵn (dữ liệu import) nên không thêm khoá ngoại;
 * app chặn xóa danh mục còn dự án.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->unsignedTinyInteger('type')->default(0)->index()->comment('0 = bài viết, 1 = dự án, 2 = sự kiện, 3 = store');
            $table->string('name', 255);
            $table->string('slug', 255);
            $table->text('description')->nullable();
            $table->integer('priority')->default(0)->comment('Nhỏ hơn đứng trước');
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique(['type', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
