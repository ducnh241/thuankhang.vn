<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\MemberKyc;
use App\Models\Order;
use App\Models\WalletTopup;
use App\Models\WalletTransaction;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Mọi thay đổi số dư ví ngoài hoa hồng (hoa hồng ghi ở CommissionEngine) đi qua đây — ví chỉ ghi thêm, số dư = tổng bút
 * toán của ví, KHÔNG BAO GIỜ âm (post() kiểm tra lại lần cuối trong transaction đã khóa dòng thành viên):
 *   - payOrder: trừ ví tái tiêu dùng khi mua hàng (CheckoutController, cùng transaction tạo đơn);
 *   - transfer: chuyển ví tái tiêu dùng cho thành viên khác theo mã giới thiệu (người chuyển phải đã duyệt KYC);
 *   - convert: chuyển từ ví rút tiền sang ví tái tiêu dùng (một chiều);
 *   - creditTopup: cộng tiền nạp đã được kế toán xác nhận (TopupPayment).
 * Khóa thành viên luôn theo thứ tự id tăng dần (giống CommissionEngine) để hai thao tác chạy cùng lúc không khóa chéo nhau.
 */
class Wallet
{
    /** Số tiền tối thiểu một lần chuyển ví cho người khác / chuyển từ ví rút tiền sang. */
    public const MIN_TRANSFER = 10000;

    public const MIN_CONVERT = 10000;

    /** Trần một lần thao tác (chống gõ nhầm thừa số 0). */
    public const MAX_AMOUNT = 1000000000;

    /**
     * Ghi một bút toán. Gọi TRONG transaction, dòng thành viên đã khóa. Số dư sau bút toán âm → ném lỗi (transaction quay lại).
     *
     * @param  array{order_id?: int, topup_id?: int, counterpart_id?: int}  $links
     */
    public static function post(Member $member, string $wallet, string $type, int $amount, string $note, array $links = []): WalletTransaction
    {
        $balance = $member->walletBalance($wallet) + $amount;
        if ($balance < 0) {
            throw new RuntimeException("Ví {$wallet} của thành viên #{$member->id} không đủ số dư (thiếu ".Money::format(-$balance).' đ).');
        }

        return WalletTransaction::create([
            'member_id' => $member->id, 'wallet' => $wallet, 'type' => $type, 'amount' => $amount, 'balance_after' => $balance,
            'note' => mb_substr($note, 0, 300), 'created_at' => now(), ...$links,
        ]);
    }

    /**
     * Khóa các thành viên theo thứ tự id (trong transaction).
     *
     * @return Collection<int, Member> theo id
     */
    public static function lock(int ...$ids): Collection
    {
        return Member::whereKey(array_unique($ids))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
    }

    /**
     * Người mua + F1 + F2 (đúng những người CommissionEngine sẽ khóa khi đơn được thanh toán). Đơn trả bằng ví khóa cả chuỗi
     * này theo thứ tự id NGAY TỪ ĐẦU — nếu chỉ khóa người mua trước rồi engine mới khóa tuyến trên, một lệnh chuyển ví từ tuyến
     * trên sang người mua chạy cùng lúc (khóa theo id tăng) có thể khóa chéo (deadlock).
     *
     * @return list<int>
     */
    public static function buyerChainIds(int $buyerId): array
    {
        $f1 = Member::whereKey($buyerId)->value('referrer_id');
        $f2 = $f1 ? Member::whereKey($f1)->value('referrer_id') : null;

        return array_values(array_unique(array_filter([$buyerId, $f1, $f2])));
    }

    /** Trừ ví tái tiêu dùng của người mua để thanh toán đơn. Gọi trong transaction tạo đơn, người mua đã khóa. */
    public static function payOrder(Member $buyer, Order $order): WalletTransaction
    {
        return self::post($buyer, WalletTransaction::WALLET_RECONSUME, WalletTransaction::TYPE_PURCHASE, -$order->total_amount,
            "Thanh toán đơn {$order->code}", ['order_id' => $order->id]);
    }

    /**
     * Chuyển $amount từ ví tái tiêu dùng của $from sang ví tái tiêu dùng của $to. Kiểm tra lại mọi điều kiện sau khi khóa.
     *
     * @return array{0: WalletTransaction, 1: WalletTransaction}|string hai bút toán (đi, đến) hoặc lý do không chuyển được
     */
    public static function transfer(Member $from, Member $to, int $amount): array|string
    {
        return DB::transaction(function () use ($from, $to, $amount) {
            $members = self::lock($from->id, $to->id);
            $sender = $members->get($from->id);
            $receiver = $members->get($to->id);
            if ($error = self::transferError($sender, $receiver, $amount)) {
                return $error;
            }

            $out = self::post($sender, WalletTransaction::WALLET_RECONSUME, WalletTransaction::TYPE_TRANSFER_OUT, -$amount,
                "Chuyển tới {$receiver->full_name} ({$receiver->referral_code})", ['counterpart_id' => $receiver->id]);
            $in = self::post($receiver, WalletTransaction::WALLET_RECONSUME, WalletTransaction::TYPE_TRANSFER_IN, $amount,
                "Nhận từ {$sender->full_name} ({$sender->referral_code})", ['counterpart_id' => $sender->id]);
            AuditLog::record('updated', $sender, ['old' => null, 'new' => ['transfer_to' => $receiver->referral_code, 'amount' => $amount]],
                "Thành viên {$sender->full_name} ({$sender->referral_code}) chuyển ".Money::format($amount)." đ ví tái tiêu dùng cho {$receiver->full_name} ({$receiver->referral_code})");

            return [$out, $in];
        }, 3);
    }

    /** Lý do không chuyển được (null = được). Dùng cả lúc xem trước (chưa khóa) và lúc chuyển (đã khóa). */
    public static function transferError(?Member $sender, ?Member $receiver, int $amount): ?string
    {
        return match (true) {
            ! $sender || $sender->isLocked() => 'Tài khoản của bạn đang bị khóa.',
            ! $sender->kyc()->where('status', MemberKyc::STATUS_APPROVED)->exists() => 'Bạn cần được duyệt KYC trước khi chuyển tiền cho thành viên khác.',
            ! $receiver => 'Không tìm thấy thành viên có mã giới thiệu này.',
            $receiver->id === $sender->id => 'Không chuyển được cho chính mình.',
            $receiver->isLocked() => 'Tài khoản người nhận đang bị khóa, không nhận được tiền.',
            $amount < self::MIN_TRANSFER => 'Số tiền chuyển tối thiểu '.Money::format(self::MIN_TRANSFER).'đ.',
            $amount > self::MAX_AMOUNT => 'Số tiền quá lớn.',
            $sender->walletBalance(WalletTransaction::WALLET_RECONSUME) < $amount => 'Số dư ví tái tiêu dùng không đủ (còn '
                .Money::format($sender->walletBalance(WalletTransaction::WALLET_RECONSUME)).'đ).',
            default => null,
        };
    }

    /**
     * Chuyển $amount từ ví rút tiền sang ví tái tiêu dùng của chính thành viên (một chiều — không chuyển ngược lại được).
     *
     * @return array{0: WalletTransaction, 1: WalletTransaction}|string
     */
    public static function convert(Member $member, int $amount): array|string
    {
        return DB::transaction(function () use ($member, $amount) {
            $member = self::lock($member->id)->first();
            $cash = $member->walletBalance(WalletTransaction::WALLET_CASH);
            $error = match (true) {
                $member->isLocked() => 'Tài khoản của bạn đang bị khóa.',
                $amount < self::MIN_CONVERT => 'Số tiền chuyển tối thiểu '.Money::format(self::MIN_CONVERT).'đ.',
                $amount > self::MAX_AMOUNT => 'Số tiền quá lớn.',
                $cash < $amount => 'Số dư ví rút tiền không đủ (còn '.Money::format($cash).'đ).',
                default => null,
            };
            if ($error) {
                return $error;
            }

            $out = self::post($member, WalletTransaction::WALLET_CASH, WalletTransaction::TYPE_CONVERT, -$amount, 'Chuyển sang ví tái tiêu dùng');
            $in = self::post($member, WalletTransaction::WALLET_RECONSUME, WalletTransaction::TYPE_CONVERT, $amount, 'Chuyển từ ví rút tiền');
            AuditLog::record('updated', $member, ['old' => null, 'new' => ['convert' => $amount]],
                "Thành viên {$member->full_name} ({$member->referral_code}) chuyển ".Money::format($amount).' đ từ ví rút tiền sang ví tái tiêu dùng');

            return [$out, $in];
        }, 3);
    }

    /** Cộng tiền nạp đã xác nhận vào ví tái tiêu dùng. Gọi trong transaction xác nhận lệnh (lệnh đã khóa). */
    public static function creditTopup(WalletTopup $topup): WalletTransaction
    {
        $member = self::lock($topup->member_id)->first();

        return self::post($member, WalletTransaction::WALLET_RECONSUME, WalletTransaction::TYPE_TOPUP, (int) $topup->paid_amount,
            "Nạp tiền — lệnh {$topup->code}", ['topup_id' => $topup->id]);
    }
}
