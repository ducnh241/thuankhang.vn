<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\Order;
use App\Services\CommissionEngine;
use App\Support\Money;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tính bù hoa hồng F1 / F2 cho các đơn ĐÃ thanh toán từ trước khi có engine hoa hồng (orders.commissioned_at còn trống).
 *
 *   php artisan commissions:backfill            xem trước: liệt kê từng đơn sẽ trả cho ai, bao nhiêu — KHÔNG ghi gì
 *   php artisan commissions:backfill --apply    ghi sổ hoa hồng + cộng ví (tất cả trong một transaction: lỗi giữa chừng
 *                                               thì không ghi gì)
 *
 * Chỉ THÊM dòng vào commission_ledger / wallet_transactions và đánh dấu orders.commissioned_at — không xóa, không sửa đơn,
 * thành viên hay dữ liệu nào khác. Chạy lại bao nhiêu lần cũng không tính đôi (đơn đã tính thì bỏ qua).
 *
 * Quy tắc như lúc xác nhận thanh toán, nhưng cấp và trạng thái kích hoạt của người hưởng lấy TẠI THỜI ĐIỂM ĐƠN ĐƯỢC
 * THANH TOÁN (CommissionEngine::stateAt), tỉ lệ theo phiên bản ma trận có hiệu lực lúc đó; ngày ghi của dòng = ngày thanh
 * toán đơn. Vì cấp lúc đó suy ra từ các đơn tự mua đã thanh toán, mọi đơn đã thanh toán phải được cộng doanh số trước
 * (php artisan levels:count-paid-orders) — lệnh dừng lại nếu còn đơn chưa cộng.
 */
class BackfillCommissions extends Command
{
    protected $signature = 'commissions:backfill {--apply : Ghi sổ hoa hồng và cộng ví (mặc định chỉ xem trước, không ghi gì)}';

    protected $description = 'Tính bù hoa hồng F1 / F2 cho các đơn đã thanh toán chưa được tính (mặc định chỉ xem trước)';

    public function handle(): int
    {
        if (! Schema::hasTable('commission_ledger') || ! Schema::hasColumn('orders', 'commissioned_at')) {
            $this->error('Chưa có bảng hoa hồng. Chạy trước: php artisan migrate');

            return self::FAILURE;
        }
        $uncounted = Order::whereIn('status', CommissionEngine::PAID_STATUSES)->whereNull('sales_counted_at')->count();
        if ($uncounted > 0) {
            $this->error("Còn {$uncounted} đơn đã thanh toán chưa được cộng doanh số / xét cấp nên chưa xác định được cấp của người hưởng.");
            $this->line('Chạy trước: php artisan levels:count-paid-orders   (rồi chạy lại lệnh này)');

            return self::FAILURE;
        }

        $ids = Order::whereIn('status', CommissionEngine::PAID_STATUSES)->whereNotNull('paid_at')->whereNull('commissioned_at')
            ->orderBy('paid_at')->orderBy('id')->pluck('id');
        if ($ids->isEmpty()) {
            $this->info('Không có đơn đã thanh toán nào chưa tính hoa hồng.');

            return self::SUCCESS;
        }

        $apply = (bool) $this->option('apply');
        $rows = [];
        $totals = [];
        $work = function () use ($ids, $apply, &$rows, &$totals) {
            foreach ($ids as $id) {
                $order = $apply ? Order::lockForUpdate()->findOrFail($id) : Order::findOrFail($id);
                if ($order->commissioned_at !== null) {
                    continue; // vừa được tính ở nơi khác
                }
                $lines = $apply ? CommissionEngine::orderPaid($order, asOfPaidAt: true) : CommissionEngine::preview($order, asOfPaidAt: true);
                if ($apply) {
                    AuditLog::record('updated', $order, ['new' => ['commissions' => $lines->map(fn (Commission $line) => "{$line->tier} #{$line->beneficiary_id}: {$line->amount}")->all()]],
                        "Tính bù hoa hồng đơn {$order->code}: ".($lines->isEmpty() ? 'không có tuyến trên' : $this->describe($lines)));
                }
                $rows[] = [$order, $lines];
                foreach ($lines->filter->isCredited() as $line) {
                    $key = "#{$line->beneficiary_id} {$line->beneficiary->full_name}";
                    $totals[$key] = ($totals[$key] ?? 0) + $line->amount;
                }
            }
        };
        $apply ? DB::transaction($work) : $work();

        // Mỗi đơn một khối (dễ đọc trên màn hình hẹp hơn là bảng nhiều cột)
        foreach ($rows as [$order, $lines]) {
            $this->line("<info>{$order->code}</info>  {$order->paid_at->format('d/m/Y H:i')}  người mua #{$order->member_id} {$order->member?->full_name}"
                .'  — tiền hàng '.Money::format($order->goods_amount).' đ, cơ sở '.Money::format(CommissionEngine::baseOf($order)['base']).' đ');
            if ($lines->isEmpty()) {
                $this->line('    (người mua không có người giới thiệu — không phát sinh hoa hồng)');
            }
            foreach ($lines as $line) {
                $this->line("    {$line->tier} → ".$this->cell($line));
            }
        }
        $this->newLine();
        $this->line($apply ? 'Đã cộng ví:' : 'Sẽ cộng ví:');
        foreach ($totals as $name => $amount) {
            $this->line("  {$name}: +".Money::format($amount).' đ');
        }
        if ($totals === []) {
            $this->line('  (không ai được cộng)');
        }
        $this->newLine();
        $apply
            ? $this->info('Đã tính bù hoa hồng cho '.count($rows).' đơn, tổng '.Money::format(array_sum($totals)).' đ.')
            : $this->warn('XEM TRƯỚC — chưa ghi gì. '.count($rows).' đơn, tổng '.Money::format(array_sum($totals)).' đ. Ghi thật: php artisan commissions:backfill --apply');

        return self::SUCCESS;
    }

    /** Một dòng F1 / F2: "#id Tên (Cấp 10%) +95.000 đ" hoặc "#id Tên: không nhận — lý do". */
    private function cell(Commission $line): string
    {
        return $line->isCredited()
            ? "#{$line->beneficiary_id} {$line->beneficiary->full_name} ({$line->level_name} ".Commission::percentLabel($line->rate_percent).') +'.Money::format($line->amount).' đ'
            : "#{$line->beneficiary_id} {$line->beneficiary->full_name}: không nhận — {$line->skip_reason}";
    }

    private function describe(Collection $lines): string
    {
        return $lines->map(fn (Commission $line) => $line->tier.' '.$line->beneficiary->full_name.' '
            .($line->isCredited() ? '+'.Money::format($line->amount).' đ' : 'không nhận'))->implode(', ');
    }
}
