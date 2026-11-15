<?php

namespace App\Support;

/**
 * Mã dùng một lần theo thời gian (TOTP — RFC 6238, HMAC-SHA1, 6 số, 30 giây): chuẩn của Google Authenticator,
 * Microsoft Authenticator, Authy, 1Password…
 */
class Totp
{
    public const PERIOD = 30;

    public const DIGITS = 6;

    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Khóa bí mật ngẫu nhiên 160 bit, dạng Base32 (32 ký tự) để gõ tay / đưa vào mã QR. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function stepAt(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? now()->getTimestamp(), self::PERIOD);
    }

    /** Mã 6 số của một bước thời gian. */
    public static function code(string $secret, int $step): string
    {
        $hash = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $number = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($number % 10 ** self::DIGITS), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Kiểm tra mã: chấp nhận lệch ±$window bước (đồng hồ điện thoại lệch tới 30 giây). Trả về bước khớp, null nếu sai.
     * $afterStep: bước của mã dùng lần trước — mã ở bước đó trở về trước không nhận lại (chống dùng lại mã vừa nhìn trộm).
     */
    public static function verify(string $secret, string $code, ?int $afterStep = null, int $window = 1, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);
        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return null;
        }
        $now = self::stepAt($timestamp);
        $matched = null;
        // Duyệt hết các bước (không dừng sớm) để thời gian xử lý không lộ bước nào khớp
        for ($step = $now - $window; $step <= $now + $window; $step++) {
            if (hash_equals(self::code($secret, $step), $code) && ($afterStep === null || $step > $afterStep)) {
                $matched ??= $step;
            }
        }

        return $matched;
    }

    /** Link otpauth:// cho mã QR (ứng dụng xác thực quét để thêm tài khoản). */
    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'.rawurlencode($issuer.':'.$account).'?'.http_build_query([
            'secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $secret): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret))) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $out .= chr(bindec($chunk));
            }
        }

        return $out;
    }
}
