<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Trang tài khoản thành viên: thông tin, cấp, link / mã giới thiệu, người giới thiệu. Bảng điều khiển đầy đủ (S8) ở Step 10. */
class AccountController extends Controller
{
    public function __invoke(Request $request): View
    {
        $member = $request->user('member')->load(['referrer', 'level']);

        return view('frontend.account', [
            'member' => $member,
            'f1Count' => $member->referrals()->count(),
        ]);
    }
}
