<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Điểm duy nhất chuyển đơn sang "Đã thanh toán". Trong MỘT transaction (đơn bị khóa): ghi thông tin thanh toán, giữ hàng,
 * ghi hoa hồng F1 / F2 vào ví tuyến trên (CommissionEngine), rồi cộng doanh số / kích hoạt / xét lên cấp người mua
 * (LevelEngine). Dùng bởi màn hình xác nhận thanh toán ở quản trị và bộ tạo dữ liệu mẫu.
 */
class OrderPayment
{
    /**
     * @param  array{payment_reference: string, paid_amount: int|string, payment_note?: ?string}  $payment
     * @return array{level: array, commissions: Collection}|false false = đơn không ở trạng thái xác nhận được
     */
    public static function confirm(Order $order, array $payment, ?int $confirmedBy): array|false
    {
        return DB::transaction(function () use ($order, $payment, $confirmedBy) {
            $order = Order::lockForUpdate()->findOrFail($order->id);
            if (! $order->canConfirmPayment()) {
                return false;
            }
            $old = $order->status;
            $reference = $payment['payment_reference'];
            $note = isset($payment['payment_note']) ? trim($payment['payment_note']) : null;
            $order->forceFill([
                'status' => Order::STATUS_PAID, 'paid_at' => now(), 'paid_amount' => (int) $payment['paid_amount'],
                'payment_reference' => $reference, 'payment_note' => $note ?: null, 'confirmed_by' => $confirmedBy,
            ])->save();
            // Đơn hủy được khôi phục: giữ hàng lại (tồn có thể âm nếu đã bán hết — kho cần kiểm tra)
            $order->load('items')->takeStock();
            AuditLog::record('updated', $order,
                ['old' => ['status' => $old], 'new' => ['status' => Order::STATUS_PAID, 'paid_amount' => (int) $payment['paid_amount'], 'payment_reference' => $reference]],
                "Xác nhận đã thanh toán đơn {$order->code} — GD {$reference}, ".Money::format($payment['paid_amount']).' đ'
                .($old === Order::STATUS_CANCELLED ? ' (khôi phục đơn đã hủy)' : '').($note ? ". Ghi chú: {$note}" : ''));

            // Step 8: hoa hồng F1 / F2 cho tuyến trên (theo cấp người hưởng lúc này) — trước khi người mua lên cấp
            $commissions = CommissionEngine::orderPaid($order);

            // Step 7: cộng doanh số cộng dồn của người mua, kích hoạt ở đơn đầu, xét lên cấp
            return ['level' => LevelEngine::orderPaid($order), 'commissions' => $commissions];
        });
    }
}
