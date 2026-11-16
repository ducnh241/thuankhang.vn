<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Thành viên trực tiếp của tôi (/tai-khoan/thanh-vien, mở từ ô "Thành viên trực tiếp (F1)" ở trang tài khoản): danh sách
 * người mình trực tiếp giới thiệu — chỉ họ tên và cấp hiện tại (không hiện số điện thoại, email, doanh số). Kèm tổng số
 * thành viên của cả nhánh (tính từ mình xuống mọi tầng bên dưới).
 */
class TeamController extends Controller
{
    public function __invoke(Request $request): View
    {
        $member = $request->user('member');

        return view('frontend.team', [
            'member' => $member,
            'directs' => $member->referrals()->with('level:id,name')->orderByDesc('id')->paginate(30, ['id', 'full_name', 'level_id', 'referrer_id']),
            'teamCount' => count($member->downlineIds()),
        ]);
    }
}
