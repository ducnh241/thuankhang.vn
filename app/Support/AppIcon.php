<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Biểu tượng ứng dụng khi khách "Thêm vào màn hình chính" (iOS / Android): tự tạo từ FAVICON đang đặt ở Cài đặt website —
 * đặt favicon lên nền trắng vuông (favicon nền trong suốt vẫn ra nền đặc, iOS không bị nền đen), có chừa lề. Tạo một lần
 * cho mỗi favicon rồi lưu ở disk public (pwa/…), đổi favicon thì tự tạo lại. Chưa có favicon, favicon không phải PNG /
 * WEBP đọc được, hoặc máy chủ thiếu GD → dùng bộ icon tĩnh mặc định trong public/assets/frontend/pwa.
 */
class AppIcon
{
    /** tên => [cạnh (px), tỉ lệ favicon so với cạnh]. Bản maskable chừa lề rộng vì Android cắt tròn / bo góc. */
    public const VARIANTS = [
        'icon-192' => [192, 0.86],
        'icon-512' => [512, 0.86],
        'icon-maskable-512' => [512, 0.64],
        'apple-touch-icon' => [180, 0.86],
    ];

    public const DISK = 'public';

    public const DIRECTORY = 'pwa';

    /** Thư mục icon tĩnh mặc định (tính từ public/). */
    public const FALLBACK = 'assets/frontend/pwa';

    /** URL của một biến thể icon (tạo nếu chưa có). $favicon = đường dẫn favicon đang lưu (settings.site_favicon). */
    public static function url(string $variant, ?string $favicon): string
    {
        $path = self::generated($variant, $favicon);

        return $path ? Storage::disk(self::DISK)->url($path) : asset(self::FALLBACK.'/'.$variant.'.png');
    }

    /** Đường dẫn (disk public) của icon tạo từ favicon hiện tại; null nếu phải dùng icon mặc định. */
    private static function generated(string $variant, ?string $favicon): ?string
    {
        if (! isset(self::VARIANTS[$variant]) || ! function_exists('imagecreatetruecolor')) {
            return null;
        }
        $disk = Storage::disk(self::DISK);
        if (! $favicon || ! $disk->exists($favicon)) {
            return null;
        }
        $key = substr(md5($favicon.'|'.$disk->lastModified($favicon).'|'.json_encode(self::VARIANTS)), 0, 12);
        $path = self::DIRECTORY."/{$key}-{$variant}.png";
        if ($disk->exists($path)) {
            return $path;
        }

        try {
            $source = @imagecreatefromstring((string) $disk->get($favicon));
            if (! $source) {
                return null; // .ico hoặc định dạng GD không đọc được
            }
            // Favicon mới → bỏ các icon tạo từ favicon cũ
            foreach ($disk->files(self::DIRECTORY) as $old) {
                if (! str_starts_with(basename($old), $key.'-')) {
                    $disk->delete($old);
                }
            }
            [$size, $ratio] = self::VARIANTS[$variant];
            $canvas = imagecreatetruecolor($size, $size);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            [$width, $height] = [imagesx($source), imagesy($source)];
            $scale = $size * $ratio / max($width, $height);
            [$w, $h] = [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
            imagealphablending($canvas, true);
            imagecopyresampled($canvas, $source, intdiv($size - $w, 2), intdiv($size - $h, 2), 0, 0, $w, $h, $width, $height);
            ob_start();
            imagepng($canvas, null, 9);
            $disk->put($path, (string) ob_get_clean());

            return $path;
        } catch (Throwable) {
            return null;
        }
    }
}
