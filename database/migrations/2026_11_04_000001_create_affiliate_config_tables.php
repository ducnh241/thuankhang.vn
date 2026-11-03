<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cấu hình affiliate (đặc tả Phase 1 – Step 1, màn hình A2–A4). Tiền lưu số nguyên (đồng), tỉ lệ % 2 chữ số lẻ.
 * Dữ liệu mặc định: database/seeders/AffiliateConfigSeeder.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        // A2 – Cấp bậc. Cấp có mốc 0 là cấp gốc "Chưa kích hoạt". Không xóa cấp, chỉ tắt.
        // Tên / thứ tự duy nhất kiểm ở app (LevelRequest): cả bảng lưu một lần nên đổi chỗ hai cấp không vướng index.
        Schema::create('levels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->index();
            $table->unsignedBigInteger('threshold_amount')->comment('Mốc doanh số cá nhân cộng dồn (đồng)');
            $table->boolean('is_vp')->default(false)->comment('Cấp VP tỉnh: được gán điểm nhận hàng');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        // A3 – Ma trận hoa hồng cấp × tầng. Mỗi lần lưu ghi thêm một phiên bản đủ mọi cấp (cùng effective_from);
        // tỉ lệ áp dụng cho đơn = dòng có effective_from mới nhất ≤ lúc đơn thanh toán. Chỉ ghi thêm.
        Schema::create('commission_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained('levels')->restrictOnDelete();
            $table->string('tier', 2)->comment('F1 | F2');
            $table->decimal('rate_percent', 5, 2);
            $table->dateTime('effective_from')->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['level_id', 'tier', 'effective_from']);
        });

        // A4 – Bậc thưởng KPI tháng: chỉ lưu tham số, Phase 2 mới tính
        Schema::create('kpi_tiers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('min_amount');
            $table->unsignedBigInteger('max_amount')->nullable()->comment('null = không giới hạn trên');
            $table->decimal('rate_percent', 5, 2);
            $table->unsignedSmallInteger('min_f1')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_tiers');
        Schema::dropIfExists('commission_rates');
        Schema::dropIfExists('levels');
    }
};
