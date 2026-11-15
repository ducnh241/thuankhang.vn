<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Bước 2 của đăng nhập quản trị khi tài khoản bật xác thực 2 lớp: đã đúng mật khẩu (LoginController ghi tài khoản đang
 * chờ vào session), giờ nhập mã 6 số từ ứng dụng xác thực hoặc một mã khôi phục. Chỉ khi đúng mới thật sự đăng nhập.
 */
class TwoFactorChallengeController extends Controller
{
    public const SESSION_KEY = 'login.two_factor';

    /** Phải nhập mã trong vòng 10 phút kể từ lúc gõ đúng mật khẩu. */
    private const TTL_SECONDS = 600;

    /** Sai quá số lần này (mỗi tài khoản, trong LOCK_SECONDS) → phải đăng nhập lại từ đầu và chờ. */
    public const MAX_ATTEMPTS = 5;

    private const LOCK_SECONDS = 900;

    public function create(Request $request): View|RedirectResponse
    {
        return $this->pendingUser($request) ? view('auth.two-factor') : $this->restart($request);
    }

    public function store(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return $this->restart($request, 'Phiên đăng nhập đã hết hạn. Vui lòng đăng nhập lại.');
        }
        $request->validate(['code' => ['required', 'string', 'max:30']], ['code.required' => 'Nhập mã xác thực.']);

        $key = 'two-factor|'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return $this->restart($request, 'Bạn đã nhập sai mã quá nhiều lần. Vui lòng thử lại sau '.ceil(RateLimiter::availableIn($key) / 60).' phút.');
        }

        $method = $twoFactor->verify($user, $request->input('code'));
        if ($method === null) {
            RateLimiter::hit($key, self::LOCK_SECONDS);
            AuditLog::record('login_failed', $user, null, 'Sai mã xác thực 2 lớp — Email: '.$user->email);

            throw ValidationException::withMessages(['code' => 'Mã không đúng hoặc đã dùng. Kiểm tra lại mã mới nhất trong ứng dụng.']);
        }

        $remember = (bool) ($request->session()->pull(self::SESSION_KEY)['remember'] ?? false);
        RateLimiter::clear($key);
        Auth::login($user, $remember);
        $request->session()->regenerate();

        $redirect = redirect()->intended(route('backend.dashboard'));
        if ($method === 'recovery') {
            $left = count($user->two_factor_recovery_codes ?? []);
            AuditLog::record('updated', $user, null, "Đăng nhập bằng mã khôi phục (còn {$left} mã)", $user);
            $redirect->with('error', "Bạn vừa đăng nhập bằng mã khôi phục — còn {$left} mã. Nếu mất thiết bị xác thực, hãy vào Tài khoản để tắt và bật lại xác thực 2 lớp.");
        }

        return $redirect;
    }

    /** Tài khoản đã qua bước mật khẩu, còn hạn, vẫn bật 2 lớp và vẫn được vào trang quản trị. */
    private function pendingUser(Request $request): ?User
    {
        $pending = $request->session()->get(self::SESSION_KEY);
        if (! is_array($pending) || now()->timestamp - (int) ($pending['at'] ?? 0) > self::TTL_SECONDS) {
            return null;
        }
        $user = User::find($pending['id'] ?? null);

        return $user && $user->hasTwoFactor() && $user->canAccessBackend() ? $user : null;
    }

    private function restart(Request $request, ?string $message = null): RedirectResponse
    {
        $request->session()->forget(self::SESSION_KEY);

        return redirect()->route('login')->withErrors($message ? ['email' => $message] : []);
    }
}
