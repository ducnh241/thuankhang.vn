<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Services\BinaryTree;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Thành viên trực tiếp của tôi (/tai-khoan/thanh-vien, mở từ ô "Đại lý bạn tuyển dụng" ở trang tài khoản): danh sách
 * người mình trực tiếp giới thiệu — họ tên, ngày tham gia (ngày đăng ký), cấp hiện tại và số người nằm dưới người đó
 * trong CÂY NHỊ PHÂN (không phải số F1 — chủ dự án chốt 24/11/2026; để biết nhánh nào đang phát triển); không hiện số điện
 * thoại, email, doanh số. Kèm tổng số thành viên của cả nhánh (mọi người nằm dưới vị trí của mình trong cây nhị phân).
 */
class TeamController extends Controller
{
    public function __invoke(Request $request): View
    {
        $member = $request->user('member');
        $directs = $member->referrals()->with('level:id,name')->orderByDesc('id')
            ->paginate(30, ['id', 'full_name', 'level_id', 'referrer_id', 'created_at']);
        // Người F1 luôn được xếp trong cây con của người giới thiệu nên một lần duyệt cây của mình là đủ cho cả trang;
        // phần tử $member->id cho ô tổng "Thành viên"
        $below = BinaryTree::countsBelow($member->id, [$member->id, ...$directs->pluck('id')->all()]);

        return view('frontend.team', [
            'member' => $member,
            'directs' => $directs,
            'below' => $below,
            'teamCount' => $below[$member->id],
        ]);
    }
}
