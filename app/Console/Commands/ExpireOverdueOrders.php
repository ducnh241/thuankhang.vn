<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hủy đơn mới quá hạn thanh toán (nguồn "expired"). Chạy mỗi phút (routes/console.php). Đơn "Chờ xác nhận thanh toán"
 * không bị hủy — khách đã báo chuyển khoản, chờ Kế toán đối chiếu.
 */
class ExpireOverdueOrders extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Hủy các đơn mới đã quá hạn thanh toán';

    public function handle(): int
    {
        $count = 0;
        Order::where('status', Order::STATUS_NEW)->where('expires_at', '<=', now())->orderBy('id')
            ->chunkById(200, function ($orders) use (&$count) {
                foreach ($orders as $order) {
                    $count += (int) DB::transaction(function () use ($order) {
                        $order = Order::lockForUpdate()->find($order->id);
                        if (! $order?->isOverdue()) {
                            return false;
                        }
                        $reason = Order::CANCEL_SOURCES[Order::CANCEL_EXPIRED];
                        $order->forceFill([
                            'status' => Order::STATUS_CANCELLED, 'cancelled_at' => $order->expires_at,
                            'cancel_source' => Order::CANCEL_EXPIRED, 'cancel_reason' => $reason,
                        ])->save();
                        $order->load('items')->releaseStock();
                        AuditLog::record('updated', $order, ['old' => ['status' => Order::STATUS_NEW], 'new' => ['status' => Order::STATUS_CANCELLED]],
                            "Đơn {$order->code}: tự hủy — {$reason}");

                        return true;
                    });
                }
            });

        $this->info("Đã hủy {$count} đơn quá hạn.");

        return self::SUCCESS;
    }
}
