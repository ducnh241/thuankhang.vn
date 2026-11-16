<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Services\LevelEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Cộng bù doanh số cho các đơn ĐÃ thanh toán từ trước khi có engine lên cấp (orders.sales_counted_at còn trống): chạy
 * LevelEngine::orderPaid cho từng đơn theo thứ tự thời gian thanh toán — cộng doanh số cộng dồn, kích hoạt, lên cấp.
 * Chạy lại bao nhiêu lần cũng không cộng đôi. Dùng một lần sau khi triển khai Step 7.
 */
class CountPaidOrders extends Command
{
    protected $signature = 'levels:count-paid-orders {--dry-run : Chỉ liệt kê, không ghi gì}';

    protected $description = 'Cộng bù doanh số cộng dồn / xét cấp cho các đơn đã thanh toán chưa được tính';

    public function handle(): int
    {
        $ids = Order::whereIn('status', [Order::STATUS_PAID, Order::STATUS_PROCESSING, Order::STATUS_COMPLETED])
            ->whereNull('sales_counted_at')->orderBy('paid_at')->orderBy('id')->pluck('id');
        if ($ids->isEmpty()) {
            $this->info('Không có đơn đã thanh toán nào chưa được tính.');

            return self::SUCCESS;
        }

        foreach ($ids as $id) {
            if ($this->option('dry-run')) {
                $order = Order::find($id);
                $this->line("{$order->code}: thành viên #{$order->member_id}, tiền hàng {$order->goods_amount}");

                continue;
            }
            $result = DB::transaction(fn () => LevelEngine::orderPaid(Order::lockForUpdate()->findOrFail($id)));
            if ($result['counted']) {
                $this->line(Order::whereKey($id)->value('code').': doanh số cộng dồn '.$result['cumulative']
                    .($result['activated'] ? ', kích hoạt' : '').($result['to'] ? ', lên cấp '.$result['to']->name : ''));
            }
        }
        $this->info(($this->option('dry-run') ? 'Sẽ tính ' : 'Đã tính ').$ids->count().' đơn.');

        return self::SUCCESS;
    }
}
