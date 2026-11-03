<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nhật ký hệ thống (đặc tả Affiliate Phase 1 – Step 0 / A14): ai làm gì, lúc nào, trên đối tượng nào, trước / sau.
     * Chỉ ghi thêm — model AuditLog chặn sửa / xóa.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            // Người thực hiện: giữ tên tại thời điểm ghi để user bị xóa vẫn đọc được log
            $table->foreignId('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            // created | updated | deleted | restored | login | logout | login_failed | bulk_deleted | …
            $table->string('action', 50)->index();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('description', 500)->nullable();
            // {old: {...}, new: {...}} — chỉ các cột đã đổi, cột nhạy cảm bị che
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('url', 500)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
