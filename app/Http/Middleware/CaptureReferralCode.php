<?php

namespace App\Http\Middleware;

use App\Support\AffiliateSettings;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * Link giới thiệu ?ref=CODE trên mọi trang website (đặc tả S1 / Step 3): ghi mã vào cookie "ref" trong số ngày cấu hình
 * (Tham số chung: ref_cookie_days, mặc định 30). Link mới ghi đè mã cũ. Mã chỉ để điền sẵn ô "Mã người giới thiệu"
 * khi đăng ký — kiểm tra mã tồn tại làm ở bước đăng ký.
 */
class CaptureReferralCode
{
    public const COOKIE = 'ref';

    public function handle(Request $request, Closure $next): Response
    {
        $code = $request->query('ref');
        if ($request->isMethod('GET') && is_string($code) && preg_match('/^[A-Za-z0-9]{3,20}$/', $code)) {
            Cookie::queue(self::COOKIE, strtoupper($code), (int) AffiliateSettings::get('ref_cookie_days') * 24 * 60);
        }

        return $next($request);
    }
}
