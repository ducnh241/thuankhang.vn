<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * API: "Authorization: Bearer tk_…". Token hợp lệ, chưa hết hạn, tài khoản còn hoạt động và còn quyền backend thì đặt
 * tài khoản đó vào guard "web" cho request này (Gate quyền + Nhật ký hoạt động như khi thao tác trong admin).
 */
class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();
        $token = $plain !== '' ? ApiToken::findByPlain($plain) : null;

        if (! $token || $token->isExpired()) {
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
