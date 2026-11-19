<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Support\AppIcon;
use App\Support\SiteSettings;
use Illuminate\Http\JsonResponse;

/**
 * Web App Manifest (/manifest.webmanifest): cho phép khách "Thêm vào màn hình chính" / "Cài đặt ứng dụng" trên Android và
 * iOS rồi mở website như một ứng dụng riêng (display: standalone — không có thanh địa chỉ của trình duyệt). Tên lấy từ Cài
 * đặt website, biểu tượng tạo từ favicon (App\Support\AppIcon). Thẻ <link rel="manifest"> + các thẻ apple-* nằm ở layout.
 */
class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $site = SiteSettings::all();
        $name = $site['site_name'] ?: config('app.name');
        $favicon = $site['site_favicon'] ?: null;

        return response()->json([
            'id' => '/',
            'name' => $name,
            'short_name' => self::shortName($name),
            ...($site['seo_description'] ? ['description' => $site['seo_description']] : []),
            'lang' => 'vi',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#ffffff',
            'icons' => [
                ['src' => AppIcon::url('icon-192', $favicon), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => AppIcon::url('icon-512', $favicon), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => AppIcon::url('icon-maskable-512', $favicon), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            // Giữ lâu trên biểu tượng ứng dụng (Android) để mở nhanh
            'shortcuts' => [
                ['name' => 'Đơn hàng của tôi', 'short_name' => 'Đơn hàng', 'url' => '/don-hang'],
                ['name' => 'Giỏ hàng', 'url' => '/gio-hang'],
                ['name' => 'Tài khoản', 'url' => '/tai-khoan'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json; charset=utf-8', 'Cache-Control' => 'public, max-age=3600'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Tên ngắn dưới biểu tượng (≤ 12 ký tự thì không bị cắt): bỏ đuôi tên miền, VD "Thuankhang.vn" → "Thuankhang". */
    public static function shortName(string $name): string
    {
        $short = trim((string) preg_replace('/\.(vn|com|net|org|shop|store)(\.vn)?$/iu', '', trim($name)));

        return mb_strlen($short) > 0 ? $short : $name;
    }
}
