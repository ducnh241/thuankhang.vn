<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MemberKyc;
use App\Models\Order;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use App\Services\StaffNotifier;
use App\Support\Money;
use App\Support\VietQr;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Throwable;

/**
 * Nạp tiền vào ví tái tiêu dùng (/tai-khoan/vi/nap-tien) — giống luồng đơn hàng chuyển khoản: nhập số tiền (tối thiểu
 * WalletTopup::MIN_AMOUNT) → lệnh NAPxxxxxxxx hiện QR công ty (số tiền + nội dung = mã lệnh), hạn payment_expiry_hours →
 * chuyển khoản, BẮT BUỘC tải ảnh bill rồi bấm "Tôi đã chuyển khoản" → chờ kế toán xác nhận (Backend\WalletTopupController)
 * → cộng số tiền thực nhận vào ví. Tối đa WalletTopup::MAX_OPEN lệnh chưa xong. Chỉ thao tác trên lệnh của chính mình.
 */
class WalletTopupController extends Controller
{
    public function index(Request $request): View
    {
        $member = $request->user('member');

        return view('frontend.wallet.topups.index', [
            'balance' => $member->walletBalance(WalletTransaction::WALLET_RECONSUME),
            'topups' => $member->walletTopups()->paginate(10),
            'limitReached' => WalletTopup::openLimitReached($member->id),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['amount' => is_string($request->input('amount')) ? (Money::parse($request->input('amount')) ?? $request->input('amount')) : $request->input('amount')]);
        $amount = (int) $request->validate(
            ['amount' => ['required', 'integer', 'min:'.WalletTopup::MIN_AMOUNT, 'max:'.WalletTopup::MAX_AMOUNT]],
            ['amount.required' => 'Nhập số tiền muốn nạp.', 'amount.integer' => 'Số tiền không hợp lệ.',
                'amount.min' => 'Số tiền nạp tối thiểu '.Money::format(WalletTopup::MIN_AMOUNT).'đ.', 'amount.max' => 'Số tiền quá lớn.'],
        )['amount'];

        $topup = DB::transaction(function () use ($request, $amount) {
            // Khóa thành viên rồi mới đếm: gửi nhiều yêu cầu cùng lúc cũng không vượt giới hạn lệnh chưa xong
            $member = Member::whereKey($request->user('member')->id)->lockForUpdate()->first();
            if (WalletTopup::openLimitReached($member->id)) {
                return null;
            }
            $topup = WalletTopup::start($member, $amount);
            AuditLog::record('created', $topup, null, "Thành viên {$member->full_name} ({$member->referral_code}) tạo lệnh nạp ví {$topup->code} — ".Money::format($amount).' đ');

            return $topup;
        });
        if (! $topup) {
            return back()->withInput()->with('error', 'Bạn đang có '.WalletTopup::MAX_OPEN.' lệnh nạp chưa xong. Hoàn tất hoặc hủy bớt lệnh cũ trước khi tạo lệnh mới.');
        }

        return redirect()->route('member.topups.show', $topup)->with('success', "Đã tạo lệnh nạp {$topup->code}. Chuyển khoản theo thông tin bên dưới, rồi tải ảnh bill và bấm “Tôi đã chuyển khoản”.");
    }

    public function show(Request $request, WalletTopup $topup): View
    {
        $this->authorizeOwner($request, $topup);
        $bank = VietQr::bank();

        return view('frontend.wallet.topups.show', [
            'topup' => $topup,
            'bank' => $bank,
            'qr' => $topup->isAwaitingPayment() ? VietQr::qr($topup->amount, $topup->code, $bank) : null,
        ]);
    }

    /** Báo đã chuyển khoản: BẮT BUỘC kèm ảnh bill. Báo lại sau khi bị trả về thì ảnh mới thay ảnh cũ. */
    public function transferred(Request $request, WalletTopup $topup): RedirectResponse
    {
        $this->authorizeOwner($request, $topup);
        [$rules, $messages] = Order::billRules();
        $request->validate(['bill' => $rules], $messages, ['bill' => 'ảnh bill']);

        $stored = null;
        try {
            $done = DB::transaction(function () use ($topup, $request, &$stored) {
                $topup = WalletTopup::lockForUpdate()->findOrFail($topup->id);
                if (! $topup->isAwaitingPayment()) {
                    return false;
                }
                $old = $topup->payment_bill;
                $stored = MemberKyc::storeImage($request->file('bill'), $topup->id, WalletTopup::BILL_DIRECTORY);
                $topup->forceFill(['status' => WalletTopup::STATUS_AWAITING, 'confirm_requested_at' => now(), 'payment_bill' => $stored])->save();
                AuditLog::record('updated', $topup, ['old' => ['status' => WalletTopup::STATUS_NEW], 'new' => ['status' => WalletTopup::STATUS_AWAITING]],
                    "Lệnh nạp {$topup->code}: thành viên báo đã chuyển khoản, kèm ảnh bill");

                return ['old' => $old];
            });
        } catch (Throwable $e) {
            MemberKyc::deleteImages($stored);
            throw $e;
        }

        if ($done) {
            MemberKyc::deleteImages($done['old']);
            StaffNotifier::topupTransferred($topup->refresh()->load('member'));
        }

        return back()->with($done ? 'success' : 'error', $done
            ? 'Cảm ơn bạn! Kế toán sẽ đối chiếu và cộng tiền vào ví sớm nhất.'
            : 'Lệnh nạp không còn ở trạng thái chờ chuyển khoản.');
    }

    public function bill(Request $request, WalletTopup $topup): Response
    {
        $this->authorizeOwner($request, $topup);
        $image = $topup->billImage();
        abort_if($image === null, 404);

        return response($image['contents'], 200, ['Content-Type' => $image['mime'], 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /** Thành viên tự hủy lệnh mới (chưa báo chuyển khoản). */
    public function cancel(Request $request, WalletTopup $topup): RedirectResponse
    {
        $this->authorizeOwner($request, $topup);
        $done = DB::transaction(function () use ($topup) {
            $topup = WalletTopup::lockForUpdate()->findOrFail($topup->id);
            if (! $topup->isAwaitingPayment()) {
                return false;
            }
            $reason = WalletTopup::CANCEL_SOURCES[WalletTopup::CANCEL_CUSTOMER];
            $topup->forceFill(['status' => WalletTopup::STATUS_CANCELLED, 'cancelled_at' => now(), 'cancel_source' => WalletTopup::CANCEL_CUSTOMER, 'cancel_reason' => $reason])->save();
            AuditLog::record('updated', $topup, ['old' => ['status' => WalletTopup::STATUS_NEW], 'new' => ['status' => WalletTopup::STATUS_CANCELLED]], "Lệnh nạp {$topup->code}: {$reason}");

            return true;
        });

        return back()->with($done ? 'success' : 'error', $done ? "Đã hủy lệnh nạp {$topup->code}." : 'Chỉ hủy được lệnh chưa báo chuyển khoản.');
    }

    private function authorizeOwner(Request $request, WalletTopup $topup): void
    {
        abort_unless($topup->member_id === $request->user('member')->id, 404);
    }
}
