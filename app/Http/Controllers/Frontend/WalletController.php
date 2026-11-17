<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Support\AffiliateSettings;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Ví hoa hồng của tôi (/tai-khoan/vi, mở từ ô "Ví hoa hồng" ở trang tài khoản): số dư, hoa hồng tháng này, tổng đã nhận
 * theo tầng F1 / F2 và lịch sử từng dòng đã cộng ví (ngày, người mua, mã đơn, cơ sở tính, tỉ lệ, số tiền). Chỉ hiện dòng đã
 * được cộng; không hiện số điện thoại / email của người mua.
 */
class WalletController extends Controller
{
    public function __invoke(Request $request): View
    {
        $member = $request->user('member')->load('level');

        return view('frontend.wallet', [
            'member' => $member,
            'wallet' => $member->walletSummary(),
            'feePercent' => AffiliateSettings::get('business_fee_percent'),
            'lines' => $member->commissions()->credited()->with(['order:id,code', 'buyer:id,full_name'])->paginate(30),
        ]);
    }
}
