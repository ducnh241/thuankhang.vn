<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * File tải lên ở màn Quản lý file (ảnh, tài liệu dùng cho trang / mô tả sản phẩm / gửi link): file nằm ở disk public,
 * thư mục uploads/YYYY/MM, tên ngẫu nhiên; bảng giữ tên gốc, loại, dung lượng, người tải.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name')->comment('Tên file gốc lúc tải lên');
            $table->string('path')->unique();
            $table->string('extension', 10);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size')->comment('byte');
            $table->timestamps();
            $table->index('extension');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_uploads');
    }
};
