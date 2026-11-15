<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class LoginController extends Controller
{
    /** Số lần đăng nhập sai tối đa trước khi khoá tạm (theo email + IP). */
    private const MAX_ATTEMPTS = 5;

    /** Thời gian khoá sau khi vượt MAX_ATTEMPTS (giây). */
    private const DECAY_SECONDS = 60;

    /**
     * Số lần sai tối đa từ MỘT IP (bất kể email nào) trong IP_DECAY_SECONDS: chặn kiểu dò "mỗi email thử vài mật khẩu"
     * — giới hạn theo email + IP ở trên không bắt được vì mỗi email có bộ đếm riêng.
     */
    public const IP_MAX_ATTEMPTS = 20;

    private const IP_DECAY_SECONDS = 600;

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
        ]);

        $throttleKey = $this->throttleKey($request);
        $ipKey = 'backend-login-ip|'.$request->ip();

        foreach ([[$throttleKey, self::MAX_ATTEMPTS], [$ipKey, self::IP_MAX_ATTEMPTS]] as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $wait = $this->waitText(RateLimiter::availableIn($key));

                throw ValidationException::withMessages([
                    'email' => "Bạn đã thử quá nhiều lần. Vui lòng đợi {$wait}.",
                ]);
            }
        }

        // Chỉ kiểm tra mật khẩu, chưa đăng nhập: tài khoản bật xác thực 2 lớp còn phải qua bước nhập mã
        if (! Auth::validate($credentials)) {
            RateLimiter::hit($throttleKey, self::DECAY_SECONDS);
            RateLimiter::hit($ipKey, self::IP_DECAY_SECONDS);
            event(new Failed('web', Auth::getLastAttempted(), $credentials)); // nhật ký "Đăng nhập sai"

            throw ValidationException::withMessages([
                'email' => 'Email hoặc mật khẩu không đúng.',
            ]);
        }

        /** @var User $user */
        $user = Auth::getLastAttempted();
        if (! $user->canAccessBackend()) {
            throw ValidationException::withMessages([
                'email' => 'Tài khoản không có quyền truy cập backend.',
            ]);
        }

        RateLimiter::clear($throttleKey);

        if ($user->hasTwoFactor()) {
            $request->session()->put(TwoFactorChallengeController::SESSION_KEY, [
                'id' => $user->id, 'remember' => $request->boolean('remember'), 'at' => now()->timestamp,
            ]);

            return redirect()->route('login.two-factor');
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        return redirect()->intended(route('backend.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Bạn đã đăng xuất.');
    }

    private function waitText(int $seconds): string
    {
        return $seconds > 90 ? ceil($seconds / 60).' phút' : $seconds.' giây';
    }

    private function throttleKey(Request $request): string
    {
        return Str::transliterate(Str::lower($request->input('email')).'|'.$request->ip());
    }
}
