<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Storage;

/**
 * Cài đặt website (trang Cài đặt, theo settings.html của template): tên site, logo, favicon, liên hệ, chân trang,
 * bản đồ, thông tin chuyển khoản + ảnh mã QR (tab Thanh toán), mạng xã hội, SEO mặc định, mã theo dõi. Lưu từng khóa vào bảng settings sẵn có (key / value), dùng cho
 * website bên ngoài; trang quản trị chỉ soạn và xem trước.
 */
class SiteSettings
{
    /** Ảnh logo / favicon trên disk public (storage/app/public/settings). */
    public const IMAGE_DIRECTORY = 'settings';

    /** Mạng xã hội ở tab "Mạng xã hội": khóa => [tên, icon, ví dụ link]. */
    public const SOCIALS = [
        'facebook' => ['Facebook', 'facebook', 'https://facebook.com/trang-cua-ban'],
        'instagram' => ['Instagram', 'instagram', 'https://instagram.com/tai-khoan'],
        'youtube' => ['YouTube', 'youtube', 'https://youtube.com/@kenh-cua-ban'],
        'tiktok' => ['TikTok', 'tiktok', 'https://tiktok.com/@tai-khoan'],
        'twitter' => ['X (Twitter)', 'twitter', 'https://x.com/tai-khoan'],
        'linkedin' => ['LinkedIn', 'linkedin', 'https://linkedin.com/company/cong-ty'],
        'zalo' => ['Zalo', 'phone', 'https://zalo.me/0900000000'],
    ];

    /** Các ô chữ (tên ô trong form = khóa trong bảng settings). Logo / favicon lưu đường dẫn ảnh. */
    public const TEXT_KEYS = [
        'site_name', 'site_hotline', 'site_email', 'site_address', 'site_footer', 'site_map',
        'seo_title', 'seo_description', 'seo_keywords', 'code_head', 'code_body', 'code_bottom',
        ...self::PAYMENT_KEYS,
    ];

    public const IMAGE_KEYS = ['site_logo', 'site_favicon', 'pay_qr'];

    /**
     * Thông tin chuyển khoản (tab Thanh toán) hiện cho khách ở trang đơn: tên ngân hàng, mã ngân hàng VietQR (để tự tạo mã
     * QR theo đơn), số tài khoản, tên chủ tài khoản; pay_qr = ảnh mã QR của công ty tải lên. Đây là nơi tiền của khách
     * chuyển về nên chỉ Root admin được sửa (như mã theo dõi).
     */
    public const PAYMENT_KEYS = ['pay_bank_name', 'pay_bank_code', 'pay_bank_account', 'pay_account_name'];

    /**
     * Mã theo dõi (tab Tracking): in nguyên văn vào mọi trang website — cùng tên miền với trang quản trị, nên ai sửa được
     * các ô này là chạy được JavaScript tùy ý trong phiên của người quản trị khác. Chỉ Root admin được sửa.
     */
    public const CODE_KEYS = ['code_head', 'code_body', 'code_bottom'];

    /** Các ô chỉ Root admin sửa được: mã theo dõi + thông tin chuyển khoản. */
    public static function rootOnlyKeys(): array
    {
        return [...self::CODE_KEYS, ...self::PAYMENT_KEYS, 'pay_qr'];
    }

    public static function socialKeys(): array
    {
        return array_map(fn ($key) => 'social_'.$key, array_keys(self::SOCIALS));
    }

    public static function keys(): array
    {
        return [...self::TEXT_KEYS, ...self::IMAGE_KEYS, ...self::socialKeys()];
    }

    /** Mọi khóa => giá trị đang lưu ('' khi chưa có), một truy vấn. */
    public static function all(): array
    {
        $stored = Setting::whereIn('key', self::keys())->pluck('value', 'key');

        return collect(self::keys())->mapWithKeys(fn ($key) => [$key => (string) ($stored[$key] ?? '')])->all();
    }

    /** Từ khóa SEO lưu dạng "a, b, c". */
    public static function keywords(?string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value)), fn ($k) => $k !== ''));
    }

    public static function imageUrl(?string $path): ?string
    {
        return $path ? Storage::disk('public')->url($path) : null;
    }

    /**
     * Link nhúng của bản đồ Google: từ mã <iframe>, link nhúng, hay link Google Maps thường (nhúng kiểu "q", không
     * cần API key). Không phải link Google Maps → null. Khớp mapUrl() ở resources/backend/js/settings.js.
     */
    public static function mapEmbedUrl(?string $value): ?string
    {
        $value = trim((string) $value);
        $src = preg_match('/src=["\']([^"\']+)["\']/i', $value, $m) ? html_entity_decode($m[1]) : $value;
        // Chỉ tên miền của Google (google.com, google.com.vn, google.co.uk, google.vn…), không nhận google.com.evil.io
        $tld = '(com(\.[a-z]{2})?|co\.[a-z]{2}|[a-z]{2})';
        if (! preg_match('#^https://((www\.)?google\.'.$tld.'/maps|maps\.google\.'.$tld.'/|goo\.gl/maps/|maps\.app\.goo\.gl/)#i', $src)) {
            return null;
        }
        if (preg_match('#/maps/embed|output=embed#', $src)) {
            return $src;
        }

        return 'https://www.google.com/maps?q='.rawurlencode($src).'&output=embed';
    }
}
