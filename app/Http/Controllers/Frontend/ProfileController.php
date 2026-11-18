<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MemberKyc;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Sửa thông tin tài khoản của thành viên (/tai-khoan/thong-tin, mở từ nút "Sửa" ở trang tài khoản): đổi họ tên hiển thị và
 * tải / đổi ảnh mã QR nhận tiền (ảnh QR tài khoản ngân hàng để kế toán quét khi chi hoa hồng). Số điện thoại (tên đăng
 * nhập), email, người giới thiệu không sửa ở đây. Mỗi thay đổi ghi một dòng nhật ký. Họ tên trong hồ sơ KYC (theo CCCD)
 * là dữ liệu riêng, không đổi theo.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('frontend.profile', [
            'member' => $request->user('member')->load('kyc'),
            'maxKilobytes' => MemberKyc::maxKilobytes(),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $member = $request->user('member');
        if (is_string($request->input('full_name'))) {
            $request->merge(['full_name' => trim(preg_replace('/\s+/u', ' ', $request->input('full_name')))]);
        }
        [$qrRules, $qrMessages] = Member::qrImageRules();
        $data = $request->validate(
            ['full_name' => ['required', 'string', 'max:100'], 'qr_image' => $qrRules],
            ['full_name.required' => 'Nhập họ tên.', 'full_name.max' => 'Họ tên tối đa 100 ký tự.', ...$qrMessages],
            ['full_name' => 'họ tên', 'qr_image' => 'ảnh mã QR'],
        );

        $changed = [];
        if ($data['full_name'] !== $member->full_name) {
            $old = $member->full_name;
            $member->forceFill(['full_name' => $data['full_name']])->saveQuietly();
            AuditLog::record('updated', $member, ['old' => ['full_name' => $old], 'new' => ['full_name' => $member->full_name]],
                "Thành viên {$member->referral_code} tự đổi họ tên: {$old} → {$member->full_name}");
            $changed[] = 'họ tên';
        }
        if ($request->hasFile('qr_image')) {
            $member->replaceQr($request->file('qr_image'), 'trang thông tin tài khoản');
            $changed[] = 'mã QR nhận tiền';
        }

        return redirect()->route('member.account')->with('success', $changed ? 'Đã cập nhật '.implode(' và ', $changed).'.' : 'Không có thay đổi nào.');
    }

    /** Ảnh mã QR nhận tiền của chính thành viên đang đăng nhập. */
    public function qr(Request $request): Response
    {
        $image = $request->user('member')->qrImage();
        abort_if($image === null, 404);

        return response($image['contents'], 200, [
            'Content-Type' => $image['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
