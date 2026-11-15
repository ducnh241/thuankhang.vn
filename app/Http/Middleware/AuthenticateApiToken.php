<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * API: "Authorization: Bearer tk_…". Token hợp lệ, chưa hết hạn, tài khoản còn hoạt động và còn quyền backend thì đặt
 * tài khoản đó vào guard "web" cho request này (Gate quyền + Nhật ký hoạt động như khi thao tác trong admin).
 *
 * Gửi sai token quá MAX_FAILURES lần / phút từ một IP → 429 (middleware throttle của route chỉ chạy sau khi đã xác thực,
 * nên phải tự chặn việc dò token / dội request rác ở đây, trước khi truy vấn database).
 */
class AuthenticateApiToken
{
    public const MAX_FAILURES = 20;

    public function handle(Request $request, Closure $next): Response
    {
        $failKey = 'api-auth-fail|'.$request->ip();
        if (RateLimiter::tooManyAttempts($failKey, self::MAX_FAILURES)) {
            $seconds = RateLimiter::availableIn($failKey);

            return response()->json(['message' => "Sai token quá nhiều lần. Thử lại sau {$seconds} giây."], 429, ['Retry-After' => $seconds]);
        }

        $plain = (string) $request->bearerToken();
        $token = $plain !== '' ? ApiToken::findByPlain($plain) : null;

        if (! $token || $token->isExpired()) {
            RateLimiter::hit($failKey, 60);

            return response()->json(['message' => 'Token API không hợp lệ hoặc đã hết hạn.'], 401);
        }
        if (! $token->user || ! $token->user->canAccessBackend()) {
            return response()->json(['message' => 'Tài khoản của token đã bị khóa hoặc không còn quyền quản trị.'], 403);
        }

        Auth::guard('web')->setUser($token->user);
        Auth::shouldUse('web');
        $request->attributes->set('api_token', $token);
        if (! $token->last_used_at || $token->last_used_at->lt(now()->subMinute())) {
            $token->forceFill(['last_used_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
