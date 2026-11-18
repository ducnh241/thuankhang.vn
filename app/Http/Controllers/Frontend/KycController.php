<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Frontend\KycRequest;
use App\Models\AuditLog;
use App\Models\MemberKyc;
use App\Services\StaffNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * KYC của thành viên (S10) ở /tai-khoan/kyc: chưa gửi / bị từ chối → form (thông tin CCCD, tài khoản ngân hàng, ảnh 2 mặt
 * CCCD, mã QR nhận tiền — không bắt buộc, lưu theo thành viên); đã gửi → trạng thái "Chờ duyệt" / "Đã duyệt" + thông tin đã gửi (số che bớt). Hồ sơ chờ duyệt / đã duyệt không
 * sửa được; bị từ chối thì gửi lại (đè hồ sơ cũ, ảnh cũ bị xóa). Quản trị duyệt ở /backend/kyc.
 */
class KycController extends Controller
{
    public function show(Request $request): View
    {
        $member = $request->user('member');

        return view('frontend.kyc', [
            'member' => $member,
            'kyc' => $member->kyc,
            'banks' => config('banks'),
            'maxKilobytes' => MemberKyc::maxKilobytes(),
            'tooLarge' => $request->boolean('too_large'),
        ]);
    }

    public function store(KycRequest $request): RedirectResponse
    {
        $member = $request->user('member');
        $data = $request->safe()->except(['front_image', 'back_image', 'qr_image']);
        $stored = [];

        try {
            $result = DB::transaction(function () use ($request, $member, $data, &$stored) {
                $kyc = MemberKyc::where('member_id', $member->id)->lockForUpdate()->first();
                if ($kyc && ! $kyc->isRejected()) {
                    return null; // đã có hồ sơ chờ duyệt / đã duyệt (vd bấm gửi hai lần)
                }
                $old = [$kyc?->front_image, $kyc?->back_image];
                $stored = [
                    'front_image' => MemberKyc::storeImage($request->file('front_image'), $member->id),
                    'back_image' => MemberKyc::storeImage($request->file('back_image'), $member->id),
                ];
                $resubmit = $kyc !== null;
                $kyc ??= new MemberKyc(['member_id' => $member->id]);
                $kyc->fill([
                    ...$data,
                    ...$stored,
                    'id_number_hash' => MemberKyc::hashIdNumber($data['id_number']),
                    'status' => MemberKyc::STATUS_PENDING,
                    'reject_reason' => null,
                    'submitted_at' => now(),
                    'reviewed_at' => null,
                    'reviewed_by' => null,
                ])->save();
                // Nhật ký: chỉ ghi việc gửi hồ sơ, không ghi số CCCD / số tài khoản
                AuditLog::record($resubmit ? 'updated' : 'created', $kyc, null,
                    "Thành viên {$member->full_name} ({$member->referral_code}) ".($resubmit ? 'gửi lại' : 'gửi').' hồ sơ KYC');

                return ['old' => $old, 'kyc' => $kyc, 'resubmit' => $resubmit];
            });
        } catch (Throwable $e) {
            MemberKyc::deleteImages(...array_values($stored));
            throw $e;
        }

        if ($result === null) {
            return redirect()->route('member.kyc')->with('error', 'Hồ sơ KYC của bạn đã được gửi trước đó.');
        }
        MemberKyc::deleteImages(...$result['old']);
        // Mã QR nhận tiền (nếu có chọn): lưu theo thành viên, thay ảnh cũ
        if ($request->hasFile('qr_image')) {
            $member->replaceQr($request->file('qr_image'), 'form KYC');
        }
        StaffNotifier::kycSubmitted($result['kyc']->setRelation('member', $member), $result['resubmit']); // báo để vào duyệt (gửi sau khi trả trang)

        return redirect()->route('member.kyc')->with('success', 'Đã gửi hồ sơ KYC. Chúng tôi sẽ duyệt trong thời gian sớm nhất.');
    }

    /** Ảnh CCCD của chính thành viên đang đăng nhập (xem lại ảnh đã gửi). */
    public function image(Request $request, string $side): Response
    {
        $image = $request->user('member')->kyc?->image($side);
        abort_if($image === null, 404);

        return response($image['contents'], 200, [
            'Content-Type' => $image['mime'],
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
