<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Điểm nhận hàng / VP tỉnh (đặc tả Step 4, màn hình A7): khách chọn "Nhận tại VP" ở checkout (Step 5) — chỉ điểm đang
 * bật. Mỗi điểm gán tối đa một thành viên cấp VP làm chủ (một thành viên chủ tối đa một điểm). Xóa mềm: đơn hàng giữ
 * điểm đã chọn kể cả khi điểm bị tắt / xóa sau đó (đặc tả 9.1 #10).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pickup_points', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('province', 30)->index()->comment('Khóa trong config/provinces.php');
            $table->string('address', 300);
            $table->string('phone', 30)->nullable();
            $table->string('opening_hours', 150)->nullable();
            $table->foreignId('owner_member_id')->nullable()->unique()->constrained('members')->nullOnDelete()
                ->comment('Thành viên cấp VP sở hữu điểm');
            $table->boolean('active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pickup_points');
    }
};
