<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Xóa sạch TOÀN BỘ đơn hàng đang có (đơn thử) để hệ thống bắt đầu nhận đơn thật. Chạy một lần lúc bắt đầu vận hành —
 * KHÔNG HOÀN TÁC ĐƯỢC, sao lưu cơ sở dữ liệu trước khi chạy. Mọi đơn có trong bảng tại thời điểm chạy đều bị xóa.
 *
 * Xóa đơn kéo theo những gì sinh ra từ đơn:
 *   1. Tồn kho: đơn đang giữ hàng (stock_reserved) thì trả số lượng về sản phẩm / biến thể có theo dõi tồn.
 *   2. Doanh số cá nhân cộng dồn của mọi thành viên về 0 (chỉ được cộng từ đơn đã thanh toán).
 *   3. Vết lên cấp do đơn sinh ra (level_histories.order_id) bị xóa; thành viên đã lên cấp nhờ đơn thử quay về cấp trước
 *      đó: cấp của lần kích hoạt thủ công gần nhất nếu có, không thì cấp trước đơn đầu tiên. Về tới cấp gốc ("Chưa kích
 *      hoạt") thì bỏ luôn ngày kích hoạt. Thành viên không có vết lên cấp theo đơn thì giữ nguyên cấp và trạng thái kích hoạt.
 *   4. Đơn và dòng đơn; số thứ tự (id) của đơn bắt đầu lại từ 1 (MySQL).
 * Không đụng tới: thành viên, cây nhị phân, KYC, sản phẩm, đánh giá sản phẩm, nhật ký thao tác (audit log).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $this->returnStock();
            $this->revertLevels();
            DB::table('members')->where('cumulative_amount', '>', 0)->update(['cumulative_amount' => 0]);
            DB::table('level_histories')->where('cumulative_after', '>', 0)->update(['cumulative_after' => 0]);
            DB::table('order_items')->delete();
            DB::table('orders')->delete();
        });

        // Ngoài transaction: ALTER TABLE trong MySQL tự commit
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE order_items AUTO_INCREMENT = 1');
            DB::statement('ALTER TABLE orders AUTO_INCREMENT = 1');
        }
    }

    public function down(): void
    {
        // Không hoàn tác được: đơn đã xóa chỉ lấy lại được từ bản sao lưu.
    }

    /** Trả hàng về kho cho các đơn đang giữ hàng — cùng quy tắc với Order::releaseStock(). */
    private function returnStock(): void
    {
        $items = DB::table('order_items')->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.stock_reserved', true)
            ->get(['order_items.product_id', 'order_items.variant_id', 'order_items.qty']);

        foreach ($items as $item) {
            $stock = $item->variant_id
                ? DB::table('product_variants')->where('id', $item->variant_id)
                : DB::table('products')->where('id', $item->product_id);
            $stock->whereNotNull('stock')->increment('stock', $item->qty);
        }
    }

    /** Xóa vết lên cấp theo đơn và đưa những thành viên đó về cấp trước khi có đơn. */
    private function revertLevels(): void
    {
        $byOrder = DB::table('level_histories')->whereNotNull('order_id')->orderBy('id')->get(['member_id', 'from_level_id'])->groupBy('member_id');
        if ($byOrder->isEmpty()) {
            return;
        }
        DB::table('level_histories')->whereNotNull('order_id')->delete();

        $baseLevelIds = DB::table('levels')->where('threshold_amount', 0)->pluck('id')->all();
        foreach ($byOrder as $memberId => $rows) {
            // Còn vết kích hoạt thủ công → cấp của lần đó; không thì cấp trước đơn đầu tiên
            $levelId = DB::table('level_histories')->where('member_id', $memberId)->orderByDesc('id')->value('to_level_id') ?? $rows->first()->from_level_id;
            if ($levelId === null) {
                continue;
            }
            $changes = ['level_id' => $levelId];
            if (in_array($levelId, $baseLevelIds, false)) {
                $changes['activated_at'] = null;
            }
            DB::table('members')->where('id', $memberId)->update($changes);
        }
    }
};
