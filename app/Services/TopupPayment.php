<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\WalletTopup;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Điểm duy nhất chuyển lệnh nạp ví sang "Đã cộng ví": trong MỘT transaction (lệnh bị khóa) ghi thông tin thanh toán rồi
 * cộng ĐÚNG SỐ TIỀN THỰC NHẬN vào ví tái tiêu dùng (Wallet::creditTopup). Không tính doanh số / hoa hồng.
 */
class TopupPayment
{
    /**
     * @param  array{payment_reference: string, paid_amount: int|string, payment_note?: ?string}  $payment
     * @return WalletTopup|false false = lệnh không ở trạng thái xác nhận được
     */
    public static function confirm(WalletTopup $topup, array $payment, ?int $confirmedBy): WalletTopup|false
    {
        return DB::transaction(function () use ($topup, $payment, $confirmedBy) {
            $topup = WalletTopup::lockForUpdate()->findOrFail($topup->id);
            if (! $topup->canConfirmPayment()) {
                return false;
            }
            $old = $topup->status;
            $note = isset($payment['payment_note']) ? trim((string) $payment['payment_note']) : null;
            $topup->forceFill([
                'status' => WalletTopup::STATUS_PAID, 'paid_at' => now(), 'paid_amount' => (int) $payment['paid_amount'],
                'payment_reference' => $payment['payment_reference'], 'payment_note' => $note ?: null, 'confirmed_by' => $confirmedBy,
            ])->save();
            $line = Wallet::creditTopup($topup);
            AuditLog::record('updated', $topup,
                ['old' => ['status' => $old], 'new' => ['status' => WalletTopup::STATUS_PAID, 'paid_amount' => $topup->paid_amount, 'payment_reference' => $topup->payment_reference]],
                "Xác nhận lệnh nạp {$topup->code} — GD {$topup->payment_reference}, cộng ".Money::format($topup->paid_amount).' đ vào ví tái tiêu dùng (số dư '
                .Money::format($line->balance_after).' đ)'.($old === WalletTopup::STATUS_CANCELLED ? ' (khôi phục lệnh đã hủy)' : '').($note ? ". Ghi chú: {$note}" : ''));

            return $topup;
        });
    }
}
