<?php

use App\Models\AuditLog;
use App\Models\Group;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Đồng chia (chủ dự án chốt 24/11/2026 — document/dong-chia-va-thuong-nam.html):
 *   - profit_share_entries: suất của thành viên trong một nhóm (nhóm = cấp có mốc ≥ mốc gói nhỏ nhất). Vào nhóm bằng MỘT đơn
 *     đã thanh toán đủ mốc cấp đó (không cộng dồn); xếp hạng theo joined_at (lúc đơn được thanh toán). received_amount =
 *     tổng đồng chia đã được kế toán duyệt; đủ cap_amount (mốc cấp lúc vào) thì exited_at — ra khỏi nhóm.
 *   - profit_share_periods / _groups / _lines: ẢNH CHỤP bảng chia của một tháng tại lúc kế toán DUYỆT (chỉ được duyệt từ
 *     ngày 1 đến ngày 5 tháng sau). Chưa duyệt thì màn hình tính trực tiếp, không lưu gì. Chỉ ghi thêm.
 *   - wallet_transactions.profit_share_line_id: bút toán cộng ví tái tiêu dùng của một dòng chia.
 *   - Quyền profit_share + profit_share/approve: tick sẵn cho nhóm đang có order/confirm_payment (Kế toán, Super admin).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profit_share_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('level_id')->constrained('levels')->restrictOnDelete()->comment('Nhóm = cấp');
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete()->comment('Đơn vào gói');
            $table->timestamp('joined_at')->comment('Lúc đơn vào gói được thanh toán — thứ tự xếp hạng');
            $table->bigInteger('order_amount');
            $table->bigInteger('cap_amount')->comment('Mốc cấp lúc vào = mức nhận đủ thì ra');
            $table->bigInteger('received_amount')->default(0)->comment('Tổng đồng chia đã duyệt');
            $table->string('exited_period', 7)->nullable()->comment('Tháng nhận đủ (YYYY-MM)');
            $table->timestamps();

            $table->unique(['level_id', 'member_id']);
            $table->index(['level_id', 'joined_at', 'id']);
        });

        Schema::create('profit_share_periods', function (Blueprint $table) {
            $table->id();
            $table->string('period', 7)->unique()->comment('YYYY-MM');
            $table->decimal('fee_percent', 5, 2);
            $table->decimal('share_percent', 5, 2);
            $table->unsignedInteger('slots');
            $table->bigInteger('order_total');
            $table->bigInteger('pool_total');
            $table->bigInteger('distributed_total');
            $table->unsignedInteger('paid_count');
            $table->string('fingerprint', 64);
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at');
            $table->timestamps();
        });

        Schema::create('profit_share_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('period_id')->constrained('profit_share_periods')->cascadeOnDelete();
            $table->foreignId('level_id')->constrained('levels')->restrictOnDelete();
            $table->string('level_name', 100);
            $table->bigInteger('min_amount');
            $table->bigInteger('max_amount')->nullable();
            $table->unsignedInteger('order_count');
            $table->bigInteger('order_total');
            $table->bigInteger('base_amount')->comment('Sau trừ phí doanh nghiệp');
            $table->bigInteger('pool');
            $table->unsignedInteger('member_count')->comment('Người còn trong nhóm, được xếp hạng');
            $table->unsignedInteger('paid_count');
            $table->bigInteger('per_head');
            $table->bigInteger('distributed');
            $table->timestamps();
        });

        Schema::create('profit_share_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('group_id')->constrained('profit_share_groups')->cascadeOnDelete();
            $table->foreignId('entry_id')->constrained('profit_share_entries')->restrictOnDelete();
            $table->foreignId('member_id')->constrained('members')->restrictOnDelete();
            $table->unsignedInteger('rank')->nullable()->comment('Hạng trong nhóm; null = không được xếp (bị khóa)');
            $table->string('status', 20)->comment('paid | waiting | skipped');
            $table->string('note', 200)->nullable();
            $table->bigInteger('received_before');
            $table->bigInteger('amount');
            $table->boolean('exits')->default(false);
            $table->timestamps();

            $table->index(['member_id', 'id']);
        });

        Schema::table('wallet_transactions', function (Blueprint $table) {
            $table->foreignId('profit_share_line_id')->nullable()->after('counterpart_id')->constrained('profit_share_lines')->restrictOnDelete();
        });

        foreach (Group::all() as $group) {
            $keys = $group->permission_data ?? [];
            if (in_array('order/confirm_payment', $keys, true) && ! in_array('profit_share/approve', $keys, true)) {
                $group->forceFill(['permission_data' => array_values(array_unique([...$keys, 'profit_share', 'profit_share/approve']))])->saveQuietly();
                AuditLog::record('updated', $group, null, "Tự thêm quyền Đồng chia (xem + duyệt) cho nhóm {$group->name} — nhóm đang có quyền xác nhận thanh toán đơn");
            }
        }
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('profit_share_line_id'));
        Schema::dropIfExists('profit_share_lines');
        Schema::dropIfExists('profit_share_groups');
        Schema::dropIfExists('profit_share_periods');
        Schema::dropIfExists('profit_share_entries');
    }
};
