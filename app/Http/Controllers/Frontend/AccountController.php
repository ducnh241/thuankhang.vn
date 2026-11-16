<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Level;
use App\Services\BinaryTree;
use App\Services\LevelEngine;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Trang tài khoản thành viên: thông tin, cấp, link / mã giới thiệu, người giới thiệu. Bảng điều khiển đầy đủ (S8) ở Step 10. */
class AccountController extends Controller
{
    public function __invoke(Request $request): View
    {
        $member = $request->user('member')->load(['referrer', 'level', 'kyc']);

        return view('frontend.account', [
            'member' => $member,
            'f1Count' => $member->referrals()->count(),
            // Thành viên của nhánh: mọi người nằm dưới vị trí của mình trong cây nhị phân (nhánh trái + nhánh phải)
            'legs' => $legs = BinaryTree::legs($member),
            'teamCount' => $legs[BinaryTree::LEFT]['count'] + $legs[BinaryTree::RIGHT]['count'],
            // Cấp kế tiếp + các cấp đang bật (trừ cấp gốc) để hiện tiến độ lên cấp
            'nextLevel' => LevelEngine::nextLevel($member),
            'levels' => Level::where('active', true)->where('threshold_amount', '>', 0)->orderBy('threshold_amount')->get(['id', 'name', 'threshold_amount']),
        ]);
    }
}
