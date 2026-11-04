<?php

namespace Database\Seeders;

use App\Models\PickupPoint;
use App\Support\AffiliateSettings;
use Illuminate\Database\Seeder;

/**
 * Điểm nhận hàng mẫu ở 6 thành phố trực thuộc trung ương (Hà Nội, TP. Hồ Chí Minh, Hải Phòng, Đà Nẵng, Cần Thơ, Huế)
 * cho dev / staging. Địa chỉ, số điện thoại là dữ liệu minh họa. Không gọi từ DatabaseSeeder:
 *   php artisan db:seed --class=DemoPickupPointSeeder
 * Chạy lại không tạo trùng (theo tên). Tỉnh đã đủ "Số VP tối đa mỗi tỉnh" thì điểm mới được tạo ở trạng thái tắt.
 */
class DemoPickupPointSeeder extends Seeder
{
    /** [tên, tỉnh, địa chỉ, SĐT, giờ mở cửa] */
    public const POINTS = [
        ['VP Thuận Khang Hà Nội – Đống Đa', 'ha-noi', '68 Láng Hạ, phường Láng, Hà Nội', '024 3856 2468', '8:00 – 17:30, Thứ 2 – Thứ 7'],
        ['VP Thuận Khang Hà Nội – Cầu Giấy', 'ha-noi', '135 Xuân Thủy, phường Cầu Giấy, Hà Nội', '024 3792 1357', '8:00 – 18:00, Thứ 2 – Chủ nhật'],
        ['VP Thuận Khang TP. Hồ Chí Minh – Sài Gòn', 'ho-chi-minh', '72 Lê Thánh Tôn, phường Sài Gòn, TP. Hồ Chí Minh', '028 3822 4680', '8:00 – 17:30, Thứ 2 – Thứ 7'],
        ['VP Thuận Khang TP. Hồ Chí Minh – Thủ Đức', 'ho-chi-minh', '215 Võ Văn Ngân, phường Thủ Đức, TP. Hồ Chí Minh', '028 3896 1357', '8:00 – 18:00, Thứ 2 – Chủ nhật'],
        ['VP Thuận Khang Hải Phòng', 'hai-phong', '24 Trần Phú, phường Ngô Quyền, Hải Phòng', '0225 3746 802', '8:00 – 17:00, Thứ 2 – Thứ 7'],
        ['VP Thuận Khang Đà Nẵng', 'da-nang', '156 Nguyễn Văn Linh, phường Thanh Khê, Đà Nẵng', '0236 3654 210', '8:00 – 17:30, Thứ 2 – Thứ 7'],
        ['VP Thuận Khang Cần Thơ', 'can-tho', '42 Hòa Bình, phường Ninh Kiều, Cần Thơ', '0292 3812 579', '7:30 – 17:00, Thứ 2 – Thứ 7'],
        ['VP Thuận Khang Huế', 'hue', '18 Lê Lợi, phường Thuận Hóa, Huế', '0234 3823 146', '7:30 – 17:00, Thứ 2 – Thứ 7'],
    ];

    public function run(): void
    {
        $max = AffiliateSettings::get('max_vp_per_province');
        foreach (self::POINTS as $i => [$name, $province, $address, $phone, $hours]) {
            if (PickupPoint::where('name', $name)->exists()) {
                continue;
            }
            $full = $max && PickupPoint::where('province', $province)->where('active', true)->count() >= $max;
            PickupPoint::create([
                'name' => $name, 'province' => $province, 'address' => $address, 'phone' => $phone,
                'opening_hours' => $hours, 'active' => ! $full, 'sort_order' => $i + 1,
            ]);
        }
    }
}
