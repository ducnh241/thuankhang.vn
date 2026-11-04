<?php

namespace App\Http\Controllers\Frontend\Auth;

use App\Http\Controllers\Controller;
use App\Models\Member;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Đăng nhập / đăng xuất thành viên website (guard "member") bằng số điện thoại + mật khẩu. */
class LoginController extends Controller
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    public function create(): View
    {
        return view('frontend.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'max:20'],
            'password' => ['required', 'string', 'max:255'],
        ], ['phone.required' => 'Nhập số điện thoại.', 'password.required' => 'Nhập mật khẩu.']);

        $phone = Member::normalizePhone($request->input('phone')) ?? trim($request->input('phone'));
        $key = 'member-login|'.$phone.'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages(['phone' => 'Bạn đã thử quá nhiều lần. Vui lòng đợi '.RateLimiter::availableIn($key).' giây.']);
        }

        if (! Auth::guard('member')->attempt(['phone' => $phone, 'password' => $request->input('password')], $request->boolean('remember'))) {
            RateLimiter::hit($key, self::DECAY_SECONDS);
            throw ValidationException::withMessages(['phone' => 'Số điện thoại hoặc mật khẩu không đúng.']);
        }

        /** @var Member $member */
        $member = Auth::guard('member')->user();
        if ($member->isLocked()) {
            Auth::guard('member')->logout();
            throw ValidationException::withMessages(['phone' => 'Tài khoản đang bị khóa. Vui lòng liên hệ hotline để được hỗ trợ.']);
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();
        $member->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('member.account'));
    }

    /** Chỉ đăng xuất thành viên: không xóa cả phiên (người quản trị dùng chung trình duyệt vẫn đăng nhập trang quản trị). */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('member')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('success', 'Bạn đã đăng xuất.');
    }
}
