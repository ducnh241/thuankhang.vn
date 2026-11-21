<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\WalletTopup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Hủy lệnh nạp ví mới quá hạn chuyển khoản (nguồn "expired"). Chạy mỗi phút (routes/console.php). Lệnh "Chờ xác nhận" không
 * bị hủy — thành viên đã báo chuyển khoản, chờ kế toán đối chiếu.
 */
class ExpireOverdueTopups extends Command
{
    protected $signature = 'topups:expire';

    protected $description = 'Hủy các lệnh nạp ví mới đã quá hạn chuyển khoản';

    public function handle(): int
    {
        $count = 0;
        WalletTopup::where('status', WalletTopup::STATUS_NEW)->where('expires_at', '<=', now())->orderBy('id')
            ->chunkById(200, function ($topups) use (&$count) {
                foreach ($topups as $topup) {
                    $count += (int) DB::transaction(function () use ($topup) {
                        $topup = WalletTopup::lockForUpdate()->find($topup->id);
                        if (! $topup?->isOverdue()) {
                            return false;
                        }
                        $reason = WalletTopup::CANCEL_SOURCES[WalletTopup::CANCEL_EXPIRED];
                        $topup->forceFill([
                            'status' => WalletTopup::STATUS_CANCELLED, 'cancelled_at' => $topup->expires_at,
                            'cancel_source' => WalletTopup::CANCEL_EXPIRED, 'cancel_reason' => $reason,
                        ])->save();
                        AuditLog::record('updated', $topup, ['old' => ['status' => WalletTopup::STATUS_NEW], 'new' => ['status' => WalletTopup::STATUS_CANCELLED]],
                            "Lệnh nạp {$topup->code}: tự hủy — {$reason}");

                        return true;
                    });
                }
            });

        $this->info("Đã hủy {$count} lệnh nạp quá hạn.");

        return self::SUCCESS;
    }
}
