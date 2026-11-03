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
        'bank' => 'Tài khoản nhận tiền (VietQR)',
        'network' => 'Mạng lưới',
    ];

    /** Đơn vị hiện cạnh ô (addon / hậu tố của Input). */
    public const UNITS = ['payment_expiry_hours' => 'giờ', 'ref_cookie_days' => 'ngày', 'max_vp_per_province' => 'VP / tỉnh'];

    public const FIELDS = [
        'business_fee_percent' => ['commission', 'Phí doanh nghiệp trừ khi tính hoa hồng', 'percent', '5.00', ['required'], 'Cơ sở hoa hồng = giá trước VAT × (1 − phí). Không tính phí ship.'],
        'commission_warning_percent' => ['commission', 'Ngưỡng cảnh báo F1 + F2', 'percent', '30.00', ['required'], 'Lưu ma trận hoa hồng có cấp vượt ngưỡng này phải xác nhận thêm.'],
        'min_withdraw' => ['commission', 'Số tiền rút tối thiểu', 'money', 500000, ['required', 'max:1000000000'], null],
        'pit_percent' => ['commission', 'Thuế TNCN khấu trừ khi rút', 'percent', null, ['nullable'], 'Chờ kế toán chốt. Chưa cấu hình thì chưa mở rút tiền (Step 9).'],
        'default_vat_percent' => ['order', 'VAT mặc định cho sản phẩm mới', 'percent', '10.00', ['required'], 'Mỗi sản phẩm vẫn chọn VAT riêng.'],
        'payment_expiry_hours' => ['order', 'Hạn thanh toán đơn', 'int', 24, ['required', 'min:1', 'max:720'], 'Quá hạn chưa chuyển khoản thì đơn tự hết hạn.'],
        'ref_cookie_days' => ['order', 'Thời gian lưu mã giới thiệu', 'int', 30, ['required', 'min:1', 'max:365'], 'Cookie ghi nhớ mã ?ref= khi khách mở link giới thiệu.'],
        'bank_code' => ['bank', 'Ngân hàng (mã BIN hoặc tên viết tắt VietQR)', 'code', null, ['nullable', 'max:20'], 'VD: 970436 hoặc VCB.'],
        'bank_account' => ['bank', 'Số tài khoản', 'code', null, ['nullable', 'max:30', 'regex:/^[0-9A-Z]+$/'], null],
        'bank_account_name' => ['bank', 'Tên chủ tài khoản', 'name', null, ['nullable', 'max:100'], 'In hoa, không dấu, đúng như trên ngân hàng (VD: CONG TY TNHH THUAN KHANG).'],
        'root_referral_code' => ['network', 'Mã giới thiệu của tài khoản gốc', 'code', null, ['nullable', 'max:20', 'regex:/^[0-9A-Z]+$/'], 'Người đăng ký không có mã sẽ gắn vào tài khoản này (cần xác nhận với khách). Kiểm tra mã tồn tại từ Step 3.'],
        'max_vp_per_province' => ['network', 'Số VP tối đa mỗi tỉnh', 'int', null, ['nullable', 'min:1', 'max:100'], 'Để trống = không giới hạn.'],
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
