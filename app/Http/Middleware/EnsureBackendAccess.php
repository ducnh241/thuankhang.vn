<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * User đã đăng nhập nhưng bị khoá hoặc group mất quyền backend_access
 * thì đăng xuất và đưa về trang login kèm thông báo.
 */
class EnsureBackendAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if ($user && ! $user->canAccessBackend()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors([
                'email' => 'Tài khoản không có quyền truy cập backend.',
            ]);
        }

        return $next($request);
    }
}
