<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Member;
use App\Models\MemberKyc;
use App\Models\WalletTransaction;
use App\Services\Wallet;
use App\Support\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Thao tác ví của thành viên (21/11/2026):
 *   - Chuyển ví tái tiêu dùng cho thành viên khác theo MÃ GIỚI THIỆU (/tai-khoan/vi/chuyen-tien): bắt buộc đã duyệt KYC,
 *     tối thiểu Wallet::MIN_TRANSFER, không phí, không hạn mức; người nhận là bất kỳ thành viên nào chưa bị khóa. Hai bước:
 *     nhập mã + số tiền → trang xác nhận hiện tên rút gọn người nhận + nhập mật khẩu đăng nhập → chuyển ngay (không cần duyệt),
 *     tiền vào ví tái tiêu dùng của người nhận.
 *   - Chuyển từ ví rút tiền sang ví tái tiêu dùng (/tai-khoan/vi/chuyen-vi): một chiều, tối thiểu Wallet::MIN_CONVERT.
 *   - Lịch sử giao dịch ví (/tai-khoan/vi/giao-dich?vi=cash|reconsume): mọi bút toán, mới nhất trước.
 */
class WalletActionController extends Controller
{
    public function transferForm(Request $request): View
    {
        $member = $request->user('member');

        return view('frontend.wallet.transfer', [
            'member' => $member,
            'balance' => $member->walletBalance(WalletTransaction::WALLET_RECONSUME),
            'kycApproved' => self::kycApproved($member),
            'preview' => null,
        ]);
    }

    /** Bước 1: kiểm tra mã + số tiền, hiện trang xác nhận (tên người nhận + ô mật khẩu). */
    public function transferPreview(Request $request): View|RedirectResponse
    {
        $member = $request->user('member');
        [$receiver, $amount] = $this->validateTransfer($request, $member);

        return view('frontend.wallet.transfer', [
            'member' => $member,
            'balance' => $member->walletBalance(WalletTransaction::WALLET_RECONSUME),
            'kycApproved' => true,
            'preview' => ['receiver' => $receiver, 'amount' => $amount],
        ]);
    }

    /** Bước 2: nhập đúng mật khẩu → chuyển. Sai / thiếu mật khẩu: ở lại trang xác nhận (giữ người nhận + số tiền). */
    public function transfer(Request $request): View|RedirectResponse
    {
        $member = $request->user('member');
        [$receiver, $amount] = $this->validateTransfer($request, $member);
        $password = $request->input('password');
        if (! is_string($password) || $password === '' || ! Hash::check($password, $member->password)) {
            return view('frontend.wallet.transfer', [
                'member' => $member,
                'balance' => $member->walletBalance(WalletTransaction::WALLET_RECONSUME),
                'kycApproved' => true,
                'preview' => ['receiver' => $receiver, 'amount' => $amount],
            ])->withErrors(['password' => is_string($password) && $password !== '' ? 'Mật khẩu không đúng.' : 'Nhập mật khẩu đăng nhập để xác nhận.']);
        }

        $result = Wallet::transfer($member, $receiver, $amount);
        if (is_string($result)) {
            return redirect()->route('member.wallet.transfer')->withInput($request->only('code', 'amount'))->withErrors(['amount' => $result]);
        }

        return redirect()->route('member.wallet.history', ['vi' => WalletTransaction::WALLET_RECONSUME])->with('success',
            'Đã chuyển '.Money::format($amount).'đ cho '.$receiver->shortName().' ('.$receiver->referral_code.'). Số dư ví tái tiêu dùng còn '
            .Money::format($result[0]->balance_after).'đ.');
    }

    public function convertForm(Request $request): View
    {
        $member = $request->user('member');

        return view('frontend.wallet.convert', [
            'cash' => $member->walletBalance(WalletTransaction::WALLET_CASH),
            'reconsume' => $member->walletBalance(WalletTransaction::WALLET_RECONSUME),
        ]);
    }

    public function convert(Request $request): RedirectResponse
    {
        $request->merge(['amount' => is_string($request->input('amount')) ? (Money::parse($request->input('amount')) ?? $request->input('amount')) : $request->input('amount')]);
        $amount = (int) $request->validate(
            ['amount' => ['required', 'integer', 'min:'.Wallet::MIN_CONVERT, 'max:'.Wallet::MAX_AMOUNT]],
            ['amount.required' => 'Nhập số tiền.', 'amount.integer' => 'Số tiền không hợp lệ.',
                'amount.min' => 'Số tiền chuyển tối thiểu '.Money::format(Wallet::MIN_CONVERT).'đ.', 'amount.max' => 'Số tiền quá lớn.'],
        )['amount'];

        $result = Wallet::convert($request->user('member'), $amount);
        if (is_string($result)) {
            return back()->withInput()->withErrors(['amount' => $result]);
        }

        return redirect()->route('member.wallet.history', ['vi' => WalletTransaction::WALLET_RECONSUME])->with('success',
            'Đã chuyển '.Money::format($amount).'đ từ ví rút tiền sang ví tái tiêu dùng. Ví tái tiêu dùng: '.Money::format($result[1]->balance_after).'đ.');
    }

    public function history(Request $request): View
    {
        $member = $request->user('member');
        $wallet = array_key_exists($key = (string) $request->query('vi'), WalletTransaction::WALLETS) ? $key : null;

        return view('frontend.wallet.history', [
            'wallet' => $wallet,
            'balances' => [
                WalletTransaction::WALLET_CASH => $member->walletBalance(WalletTransaction::WALLET_CASH),
                WalletTransaction::WALLET_RECONSUME => $member->walletBalance(WalletTransaction::WALLET_RECONSUME),
            ],
            'rows' => $member->walletTransactions()->when($wallet, fn ($q) => $q->where('wallet', $wallet))
                ->with(['order:id,code', 'topup:id,code', 'counterpart:id,full_name,referral_code'])
                ->paginate(30)->withQueryString(),
        ]);
    }

    /**
     * Kiểm tra form chuyển tiền; lỗi → quay lại form (ValidationException). Trả [người nhận, số tiền].
     *
     * @return array{0: Member, 1: int}
     */
    private function validateTransfer(Request $request, Member $member): array
    {
        $request->merge([
            'amount' => is_string($request->input('amount')) ? (Money::parse($request->input('amount')) ?? $request->input('amount')) : $request->input('amount'),
            'code' => is_string($request->input('code')) ? strtoupper(trim($request->input('code'))) : $request->input('code'),
        ]);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'amount' => ['required', 'integer', 'min:'.Wallet::MIN_TRANSFER, 'max:'.Wallet::MAX_AMOUNT],
        ], [
            'code.required' => 'Nhập mã giới thiệu của người nhận.',
            'amount.required' => 'Nhập số tiền.',
            'amount.integer' => 'Số tiền không hợp lệ.',
            'amount.min' => 'Số tiền chuyển tối thiểu '.Money::format(Wallet::MIN_TRANSFER).'đ.',
            'amount.max' => 'Số tiền quá lớn.',
        ]);

        $receiver = Member::findByReferralCode($data['code']);
        $amount = (int) $data['amount'];
        $back = fn (string $field, string $message) => throw ValidationException::withMessages([$field => $message])
            ->redirectTo(route('member.wallet.transfer'));

        if ($error = Wallet::transferError($member, $receiver, $amount)) {
            $back(! $receiver || $receiver->id === $member->id || $receiver->isLocked() ? 'code' : 'amount', $error);
        }

        return [$receiver, $amount];
    }

    private static function kycApproved(Member $member): bool
    {
        return $member->kyc()->where('status', MemberKyc::STATUS_APPROVED)->exists();
    }
}
