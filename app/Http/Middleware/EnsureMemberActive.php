<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Thành viên đang đăng nhập mà bị khóa: đăng xuất và về trang đăng nhập kèm thông báo. */
class EnsureMemberActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user('member')?->isLocked()) {
            Auth::guard('member')->logout();
            $request->session()->regenerateToken();

            return redirect()->route('member.login')->withErrors(['phone' => 'Tài khoản đang bị khóa. Vui lòng liên hệ hotline để được hỗ trợ.']);
        }

        return $next($request);
    }
}
