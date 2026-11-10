<?php

use App\Models\Level;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bỏ cấp "Cửa hàng" (mốc 10tr) khỏi cấu hình cấp bậc: xóa cấp + các dòng tỉ lệ hoa hồng của cấp này (chưa có đơn nào tính
 * theo — bảng tỉ lệ vẫn chỉ ghi thêm với mọi thay đổi khác), dồn thứ tự các cấp phía sau. Còn thành viên ở cấp này thì
 * dừng: phải chuyển họ sang cấp khác trước (hệ thống không tự hạ / nâng cấp).
 */
return new class extends Migration
{
    public function up(): void
    {
        $level = DB::table('levels')->where('name', 'Cửa hàng')->first();
        if (! $level) {
            return;
        }
        $members = DB::table('members')->where('level_id', $level->id)->count();
        if ($members > 0) {
            throw new RuntimeException("Còn {$members} thành viên ở cấp “Cửa hàng” — chuyển họ sang cấp khác trước khi bỏ cấp này.");
        }

        DB::transaction(function () use ($level) {
            DB::table('commission_rates')->where('level_id', $level->id)->delete();
            DB::table('levels')->where('id', $level->id)->delete();
            DB::table('levels')->where('sort_order', '>', $level->sort_order)->orderBy('sort_order')->decrement('sort_order');
            DB::table('audit_logs')->insert([
                'user_name' => 'system', 'action' => 'deleted', 'auditable_type' => Level::class, 'auditable_id' => $level->id,
                'description' => 'Bỏ cấp “Cửa hàng” (mốc '.number_format($level->threshold_amount, 0, ',', '.').' đ) khỏi cấu hình cấp bậc',
                'changes' => json_encode(['old' => ['name' => $level->name, 'sort_order' => $level->sort_order, 'threshold_amount' => $level->threshold_amount], 'new' => null], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Không khôi phục tự động: thêm lại cấp ở trang Cấu hình cấp bậc nếu cần.
    }
};
