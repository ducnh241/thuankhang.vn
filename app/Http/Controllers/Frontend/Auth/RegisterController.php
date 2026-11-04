<?php

namespace App\Http\Controllers\Frontend\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\CaptureReferralCode;
use App\Http\Requests\Frontend\RegisterRequest;
use App\Models\Level;
use App\Models\Member;
use App\Support\AffiliateSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Đăng ký thành viên (S3, Step 3). Ô "Mã người giới thiệu" điền sẵn từ cookie ref (link ?ref=CODE), sửa được trước khi
 * gửi; trang hiện tên rút gọn của người giới thiệu để xác nhận. Không nhập mã → gắn vào tài khoản gốc (Tham số chung:
 * root_referral_code) nếu đã cấu hình. Người giới thiệu gắn cố định; cấp mặc định là cấp gốc "Chưa kích hoạt".
 */
class RegisterController extends Controller
{
    public function create(Request $request): View
    {
        $code = $this->queryString($request, 'ref') ?: (string) $request->cookie(CaptureReferralCode::COOKIE);
        $code = preg_match('/^[A-Za-z0-9]{3,20}$/', $code) ? strtoupper($code) : '';
        $referrer = Member::findByReferralCode($code);

        return view('frontend.auth.register', [
            'code' => $code,
            'referrer' => $referrer && ! $referrer->isLocked() ? $referrer : null,
        ]);
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $referrer = $request->filled('referral_code')
            ? Member::findByReferralCode($request->validated('referral_code'))
            : $this->rootAccount();

        $member = DB::transaction(fn () => Member::create([
            'full_name' => $request->validated('full_name'),
            'phone' => $request->validated('phone'),
            'email' => $request->validated('email'),
            'password' => $request->validated('password'),
            'referral_code' => Member::generateReferralCode(),
            'referrer_id' => $referrer?->id,
            'level_id' => Level::where('threshold_amount', 0)->orderBy('sort_order')->value('id'),
        ]));

        Auth::guard('member')->login($member);
        $request->session()->regenerate();
        $member->forceFill(['last_login_at' => now()])->save();
        Cookie::queue(Cookie::forget(CaptureReferralCode::COOKIE));

        return redirect()->route('member.account')->with('success', 'Đăng ký thành công. Chào mừng '.$member->full_name.'!');
    }

    /** Tra mã giới thiệu khi đang gõ ở form đăng ký: trả tên rút gọn để xác nhận (không lộ thông tin khác). */
    public function lookup(string $code): JsonResponse
    {
        $member = preg_match('/^[A-Za-z0-9]{3,20}$/', $code) ? Member::findByReferralCode($code) : null;

        return $member && ! $member->isLocked()
            ? response()->json(['name' => $member->shortName()])
            : response()->json(['message' => 'Mã giới thiệu không tồn tại.'], 404);
    }

    /** Tài khoản gốc của công ty cho người đăng ký không có mã (cấu hình ở Tham số chung). */
    private function rootAccount(): ?Member
    {
        $root = Member::findByReferralCode(AffiliateSettings::get('root_referral_code'));

        return $root && ! $root->isLocked() ? $root : null;
    }
}
