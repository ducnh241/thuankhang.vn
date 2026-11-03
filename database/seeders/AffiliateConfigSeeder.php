<?php

namespace Database\Seeders;

use App\Models\CommissionRate;
use App\Models\KpiTier;
use App\Models\Level;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Cấu hình affiliate mặc định theo đặc tả mục 2 (cấp bậc, hoa hồng, KPI). Chỉ nạp vào bảng còn trống: chạy lại không
 * ghi đè cấu hình admin đã sửa. Tham số chung (A5) không cần seed — dùng mặc định trong App\Support\AffiliateSettings.
 */
class AffiliateConfigSeeder extends Seeder
{
    /** [tên, thứ tự, mốc (đ), là VP]. Mốc "Nhân viên" và tên 2 mức VP chờ khách xác nhận (câu hỏi mở #4). */
    public const LEVELS = [
        ['Chưa kích hoạt', 0, 0, false],
        ['Nhân viên', 1, 1, false],
        ['Cộng tác viên', 2, 3_000_000, false],
        ['Cửa hàng', 3, 10_000_000, false],
        ['Đại lý', 4, 15_000_000, false],
        ['VP tỉnh cấp 1', 5, 150_000_000, true],
        ['VP tỉnh cấp 2', 6, 300_000_000, true],
    ];

    /** Tỉ lệ F1 mặc định theo tên cấp; cấp / tầng khác = 0 (admin cấu hình). */
    public const F1_RATES = ['Cộng tác viên' => '10.00', 'Đại lý' => '20.00'];

    /** [từ (đ), đến (đ) | null, %, số F1 tối thiểu] — đặc tả mục 2.6. */
    public const KPI_TIERS = [
        [10_000_000, 100_000_000, '3.00', 5],
        [100_000_000, 500_000_000, '5.00', 5],
        [500_000_000, 1_000_000_000, '7.00', 5],
        [1_000_000_000, null, '9.00', 5],
    ];

    public function run(): void
    {
        DB::transaction(function () {
            if (! Level::exists()) {
                foreach (self::LEVELS as [$name, $order, $threshold, $vp]) {
                    Level::create(['name' => $name, 'sort_order' => $order, 'threshold_amount' => $threshold, 'is_vp' => $vp]);
                }
            }

            if (! CommissionRate::exists()) {
                $now = now()->startOfMinute();
                foreach (Level::earning() as $level) {
                    foreach (array_keys(CommissionRate::TIERS) as $tier) {
                        CommissionRate::create([
                            'level_id' => $level->id, 'tier' => $tier, 'effective_from' => $now,
                            'rate_percent' => $tier === 'F1' ? (self::F1_RATES[$level->name] ?? '0.00') : '0.00',
                        ]);
                    }
                }
            }

            if (! KpiTier::exists()) {
                foreach (self::KPI_TIERS as [$min, $max, $rate, $minF1]) {
                    KpiTier::create(['min_amount' => $min, 'max_amount' => $max, 'rate_percent' => $rate, 'min_f1' => $minF1]);
                }
            }
        });
    }
}
