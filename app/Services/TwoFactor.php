<?php

namespace App\Services;

use App\Models\User;
use App\Support\Totp;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;

/**
 * Xác thực 2 lớp (TOTP) của tài khoản quản trị: bật (tạo khóa → quét QR → nhập mã xác nhận), kiểm tra mã lúc đăng nhập
 * (mã 6 số của ứng dụng, hoặc mã khôi phục dùng một lần), tắt. Khóa bí mật lưu mã hóa; mã khôi phục chỉ lưu SHA-256.
 * Các cột two_factor_* lưu bằng saveQuietly — nhật ký hệ thống do nơi gọi tự ghi (không chép khóa vào log).
 */
class TwoFactor
{
    public const RECOVERY_CODES = 8;

    /** Bước 1: tạo khóa bí mật mới, chưa có hiệu lực cho tới khi xác nhận bằng một mã đúng. */
    public function begin(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => Totp::generateSecret(), 'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null, 'two_factor_last_step' => null,
        ])->saveQuietly();
    }

    /** Bước 2: nhập đúng mã từ ứng dụng → bật. Trả về các mã khôi phục (chỉ lúc này mới có bản gốc), null nếu mã sai. */
    public function confirm(User $user, string $code): ?array
    {
        if (! $user->two_factor_secret || $user->hasTwoFactor()) {
            return null;
        }
        $step = Totp::verify($user->two_factor_secret, $code);
        if ($step === null) {
            return null;
        }
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_last_step' => $step])->saveQuietly();

        return $this->regenerateRecoveryCodes($user);
    }

    /** @return string[] mã khôi phục mới (bản gốc); các mã cũ mất hiệu lực */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = collect(range(1, self::RECOVERY_CODES))->map(fn () => Str::lower(Str::random(5).'-'.Str::random(5)))->all();
        $user->forceFill(['two_factor_recovery_codes' => array_map(fn ($code) => hash('sha256', $code), $codes)])->saveQuietly();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null, 'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null, 'two_factor_last_step' => null,
        ])->saveQuietly();
    }

    /**
     * Kiểm tra lúc đăng nhập: mã 6 số (mỗi mã chỉ dùng một lần) hoặc mã khôi phục (dùng xong bị xóa).
     * Trả về 'totp' / 'recovery' khi đúng, null khi sai.
     */
    public function verify(User $user, string $input): ?string
    {
        if (! $user->hasTwoFactor()) {
            return null;
        }
        $input = trim($input);
        $step = Totp::verify($user->two_factor_secret, $input, $user->two_factor_last_step);
        if ($step !== null) {
            $user->forceFill(['two_factor_last_step' => $step])->saveQuietly();

            return 'totp';
        }

        $hash = hash('sha256', Str::lower(preg_replace('/\s+/', '', $input)));
        $codes = $user->two_factor_recovery_codes ?? [];
        foreach ($codes as $i => $stored) {
            if (hash_equals($stored, $hash)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->saveQuietly();

                return 'recovery';
            }
        }

        return null;
    }

    /** Mã QR (SVG nội tuyến) của link otpauth — tạo ngay trên máy chủ, không gửi khóa bí mật cho dịch vụ ngoài. */
    public function qrSvg(User $user): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd)))
            ->writeString(Totp::uri($user->two_factor_secret, $user->email, config('app.name')));

        return trim(substr($svg, strpos($svg, '<svg')));
    }
}
