<?php

namespace App\Support;

use App\Models\Setting;

/**
 * Tham số chung của affiliate (A5, đặc tả mục 6.5) + ngày trả KPI (sửa ở trang KPI). Lưu từng khóa vào bảng settings
 * (key / value); khóa chưa lưu dùng giá trị mặc định dưới đây. Đọc: AffiliateSettings::get('business_fee_percent').
 *
 * Mỗi khóa: [nhóm (thẻ trên trang), nhãn, kiểu, mặc định, ràng buộc, gợi ý].
 *   kiểu: percent (chuỗi "5.00"), money (int đồng), int, text, code (chữ in hoa / số), name (in hoa)
 *   mặc định null = chưa cấu hình (bắt buộc cấu hình trước khi dùng chức năng liên quan)
 */
class AffiliateSettings
{
    /** Tiền tố khóa trong bảng settings, tránh trùng cài đặt website. */
    public const PREFIX = 'aff_';

    public const GROUPS = [
        'commission' => 'Hoa hồng & thuế',
        'order' => 'Đơn hàng & giới thiệu',
        'network' => 'Mạng lưới',
        'profit_share' => 'Đồng chia',
    ];

    /** Đơn vị hiện cạnh ô (addon / hậu tố của Input). */
    public const UNITS = ['payment_expiry_hours' => 'giờ', 'ref_cookie_days' => 'ngày', 'max_vp_per_province' => 'VP / tỉnh', 'profit_share_slots' => 'người'];

    public const FIELDS = [
        'business_fee_percent' => ['commission', 'Phí doanh nghiệp trừ khi tính hoa hồng', 'percent', '5.00', ['required'], 'Cơ sở hoa hồng = tiền hàng (giá bán đã gồm thuế) × (1 − phí). Không tính phí ship.'],
        'commission_warning_percent' => ['commission', 'Ngưỡng cảnh báo F1 + F2', 'percent', '30.00', ['required'], 'Lưu ma trận hoa hồng có cấp vượt ngưỡng này phải xác nhận thêm.'],
        'min_withdraw' => ['commission', 'Số tiền rút tối thiểu', 'money', 500000, ['required', 'max:1000000000'], null],
        'pit_percent' => ['commission', 'Thuế TNCN khấu trừ từ hoa hồng', 'percent', '10.00', ['required'], 'Trích ngay từ mỗi khoản hoa hồng lúc cộng ví (không vào ví nào). Đổi chỉ áp dụng cho hoa hồng tính sau đó.'],
        'reconsume_percent' => ['commission', 'Phần hoa hồng vào ví tái tiêu dùng', 'percent', '20.00', ['required'], 'Phần còn lại = 100% − tái tiêu dùng − thuế TNCN (mặc định 70%) vào ví rút tiền. Đổi chỉ áp dụng cho hoa hồng tính sau đó.'],
        'order_code_prefix' => ['order', 'Tiền tố mã đơn hàng', 'code', 'TK', ['required', 'max:4', 'regex:/^[A-Z]+$/'], 'Mã đơn = tiền tố + 8 ký tự (VD: TK7H2KQ9MX). 1–4 chữ cái in hoa, không dấu. Đổi chỉ áp dụng cho đơn mới.'],
        'payment_expiry_hours' => ['order', 'Hạn thanh toán đơn', 'int', 24, ['required', 'min:1', 'max:720'], 'Quá hạn chưa chuyển khoản thì đơn tự hết hạn.'],
        'ref_cookie_days' => ['order', 'Thời gian lưu mã giới thiệu', 'int', 30, ['required', 'min:1', 'max:365'], 'Cookie ghi nhớ mã ?ref= khi khách mở link giới thiệu.'],
        'root_member_code' => ['network', 'Mã giới thiệu của ông tổng (gốc cây nhị phân)', 'code', null, ['nullable', 'max:20', 'regex:/^[0-9A-Z]+$/'], 'Thành viên đứng đầu hệ thống. Để trống = thành viên không có người giới thiệu đăng ký sớm nhất. Đổi ở đây không dời vị trí ai đã xếp.'],
        'max_vp_per_province' => ['network', 'Số VP tối đa mỗi tỉnh', 'int', null, ['nullable', 'min:1', 'max:100'], 'Để trống = không giới hạn.'],
        'profit_share_percent' => ['profit_share', 'Tỉ lệ quỹ đồng chia', 'percent', '5.00', ['required'], 'Quỹ mỗi nhóm = tổng tiền các đơn vào gói thanh toán trong tháng × (1 − phí doanh nghiệp) × tỉ lệ này. Đổi chỉ áp dụng cho tháng chưa duyệt.'],
        'profit_share_min_amount' => ['profit_share', 'Mốc gói nhỏ nhất tính đồng chia', 'money', 3000000, ['required', 'min:1', 'max:100000000000'], 'Mỗi cấp có mốc từ số này trở lên là một nhóm đồng chia (theo trang Cấp bậc). Cấp có mốc thấp hơn (vd Khách hàng) không chia.'],
        'profit_share_slots' => ['profit_share', 'Số người được chia mỗi nhóm (top)', 'int', 100, ['required', 'min:1', 'max:10000'], 'Nhóm đông hơn số này thì chỉ người vào trước được chia, còn lại chờ.'],
        // Sửa ở trang KPI (A4)
        'kpi_pay_day' => [null, 'Ngày trả thưởng KPI hằng tháng', 'int', 10, ['required', 'min:1', 'max:28'], null],
    ];

    /** Giá trị đã ép kiểu (percent: "5.00", money / int: int, còn lại: chuỗi); chưa lưu → mặc định. */
    public static function get(string $key): string|int|null
    {
        return self::cast($key, self::stored()[$key] ?? null);
    }

    /** Mọi khóa => giá trị (đã ép kiểu), một truy vấn. */
    public static function all(): array
    {
        $stored = self::stored();

        return collect(self::FIELDS)->keys()->mapWithKeys(fn ($key) => [$key => self::cast($key, $stored[$key] ?? null)])->all();
    }

    private static function cast(string $key, ?string $raw): string|int|null
    {
        $field = self::FIELDS[$key] ?? throw new \InvalidArgumentException("Không có tham số affiliate '{$key}'.");
        if ($raw === null || $raw === '') {
            return $field[3];
        }

        return in_array($field[2], ['money', 'int'], true) ? (int) $raw : $raw;
    }

    /** Lưu các khóa đã đổi (giá trị đã chuẩn hóa: chuỗi / int / null). Ghi nhật ký qua observer của Setting. */
    public static function save(array $values): void
    {
        $current = self::all();
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, self::FIELDS) || (string) $value === (string) $current[$key]) {
                continue;
            }
            Setting::put(self::PREFIX.$key, $value === null ? null : (string) $value);
        }
    }

    /** Hiển thị trong ô nhập: percent "5,5", money "500.000". */
    public static function display(string $key, string|int|null $value): string
    {
        return match (self::FIELDS[$key][2]) {
            'percent' => Percent::format($value),
            'money' => $value === null ? '' : Money::format($value),
            default => (string) $value,
        };
    }

    private static function stored(): array
    {
        return Setting::whereIn('key', array_map(fn ($key) => self::PREFIX.$key, array_keys(self::FIELDS)))->pluck('value', 'key')
            ->mapWithKeys(fn ($value, $key) => [substr($key, strlen(self::PREFIX)) => $value])->all();
    }
}
